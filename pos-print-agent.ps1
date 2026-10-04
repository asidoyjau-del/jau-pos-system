# ==============================================================================
# POS Native Print Agent (Windows Background Service)
# Automatically receives print jobs via local HTTP (port 9100) and sends raw 
# ESC/POS commands directly to Xprinter XP-58 via winspool.drv.
# Completely eliminates the browser print preview modal!
# ==============================================================================

param(
    [int]$Port = 9100,
    [string]$PrinterName = "Xprinter XP-58"
)

$ErrorActionPreference = 'Continue'

# Compile C# RawPrinterHelper
Add-Type -TypeDefinition @"
using System;
using System.IO;
using System.Runtime.InteropServices;

public class RawPrinterHelper {
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Ansi)]
    public class DOCINFOA {
        [MarshalAs(UnmanagedType.LPStr)] public string pDocName;
        [MarshalAs(UnmanagedType.LPStr)] public string pOutputFile;
        [MarshalAs(UnmanagedType.LPStr)] public string pDataType;
    }
    [DllImport("winspool.drv", EntryPoint = "OpenPrinterA", SetLastError = true, CharSet = CharSet.Ansi, ExactSpelling = true, CallingConvention = CallingConvention.StdCall)]
    public static extern bool OpenPrinter([MarshalAs(UnmanagedType.LPStr)] string szPrinter, out IntPtr hPrinter, IntPtr pd);

    [DllImport("winspool.drv", EntryPoint = "ClosePrinter", SetLastError = true, ExactSpelling = true, CallingConvention = CallingConvention.StdCall)]
    public static extern bool ClosePrinter(IntPtr hPrinter);

    [DllImport("winspool.drv", EntryPoint = "StartDocPrinterA", SetLastError = true, CharSet = CharSet.Ansi, ExactSpelling = true, CallingConvention = CallingConvention.StdCall)]
    public static extern bool StartDocPrinter(IntPtr hPrinter, int level, [In, MarshalAs(UnmanagedType.LPStruct)] DOCINFOA di);

    [DllImport("winspool.drv", EntryPoint = "EndDocPrinter", SetLastError = true, ExactSpelling = true, CallingConvention = CallingConvention.StdCall)]
    public static extern bool EndDocPrinter(IntPtr hPrinter);

    [DllImport("winspool.drv", EntryPoint = "StartPagePrinter", SetLastError = true, ExactSpelling = true, CallingConvention = CallingConvention.StdCall)]
    public static extern bool StartPagePrinter(IntPtr hPrinter);

    [DllImport("winspool.drv", EntryPoint = "EndPagePrinter", SetLastError = true, ExactSpelling = true, CallingConvention = CallingConvention.StdCall)]
    public static extern bool EndPagePrinter(IntPtr hPrinter);

    [DllImport("winspool.drv", EntryPoint = "WritePrinter", SetLastError = true, ExactSpelling = true, CallingConvention = CallingConvention.StdCall)]
    public static extern bool WritePrinter(IntPtr hPrinter, IntPtr pBytes, int dwCount, out int dwWritten);

    // Stage reached by the last print job:
    //   0 = failed (printer not found / cannot open)
    //   1 = printer opened, job not yet created
    //   2 = job created in Windows spooler (will print as soon as printer is reachable)
    //   3 = bytes written
    //   4 = job fully completed
    public class JobState { public volatile int Stage = 0; }

    public static int SendBytesToPrinterEx(string szPrinterName, byte[] pBytes, int timeoutMs) {
        JobState st = new JobState();
        System.Threading.Thread t = new System.Threading.Thread(() => {
            IntPtr hPrinter = IntPtr.Zero;
            DOCINFOA di = new DOCINFOA();
            di.pDocName = "POS Auto Receipt";
            di.pDataType = "RAW";
            try {
                if (OpenPrinter(szPrinterName.Normalize(), out hPrinter, IntPtr.Zero)) {
                    st.Stage = 1;
                    if (StartDocPrinter(hPrinter, 1, di)) {
                        st.Stage = 2;
                        bool written = false;
                        if (StartPagePrinter(hPrinter)) {
                            IntPtr pUnmanagedBytes = Marshal.AllocCoTaskMem(pBytes.Length);
                            Marshal.Copy(pBytes, 0, pUnmanagedBytes, pBytes.Length);
                            int dwWritten = 0;
                            written = WritePrinter(hPrinter, pUnmanagedBytes, pBytes.Length, out dwWritten);
                            Marshal.FreeCoTaskMem(pUnmanagedBytes);
                            if (written) st.Stage = 3;
                            EndPagePrinter(hPrinter);
                        }
                        EndDocPrinter(hPrinter);
                        if (written) st.Stage = 4;
                    }
                    ClosePrinter(hPrinter);
                }
            } catch {}
        });
        t.IsBackground = true;
        t.Start();
        // Never Abort the thread: aborting mid-WritePrinter corrupts the spool job.
        // If the USB printer is unplugged/offline the call may block, but once the
        // job is in the spooler (Stage >= 2) Windows will print it on reconnect.
        t.Join(timeoutMs);
        return st.Stage;
    }

    public static bool SendBytesToPrinter(string szPrinterName, byte[] pBytes) {
        return SendBytesToPrinterEx(szPrinterName, pBytes, 2500) >= 2;
    }
}
"@

# Universal Drawer Kick Pulse:
# 1. ESC p 0 25 250 (Pin 2 standard 24V/12V pulse)
# 2. ESC p 1 25 250 (Pin 5 standard 24V/12V pulse)
# 3. ESC p '0' 25 250 (ASCII 0x30 for older POS-58 firmware)
# 4. ESC p '1' 25 250 (ASCII 0x31 for older POS-58 firmware)
# 5. DLE DC4 1 0 1 (Real-Time Pin 2 pulse - bypasses spooler backlog)
# 6. DLE DC4 1 1 1 (Real-Time Pin 5 pulse - bypasses spooler backlog)
# 7. ASCII BEL 0x07 (Star Micronics & legacy thermal drawers)
$script:DRAWER_PULSE = [byte[]]@(
    0x1B, 0x70, 0x00, 0x19, 0xFA,
    0x1B, 0x70, 0x01, 0x19, 0xFA,
    0x1B, 0x70, 0x30, 0x19, 0xFA,
    0x1B, 0x70, 0x31, 0x19, 0xFA,
    0x10, 0x14, 0x01, 0x00, 0x01,
    0x10, 0x14, 0x01, 0x01, 0x01,
    0x07
)

# Keywords that identify a THERMAL receipt printer. We never fall back to the
# Windows default printer, because sending raw ESC/POS bytes to an inkjet,
# "Microsoft Print to PDF" (opens a Save dialog) or XPS writer causes garbage
# pages or pop-ups. If no thermal printer is installed we use TEST MODE instead.
$script:THERMAL_KEYWORDS = @(
    'XP-58', 'XP-80', 'XP58', 'XP80', 'Xprinter', 'POS-58', 'POS-80', 'POS58', 'POS80',
    'Thermal', 'Receipt', 'TM-T', 'TM-U', 'RP58', 'RP80', 'GP-58', 'GP-80', 'ZJ-58', 'ZJ-80',
    'EPSON TM', 'Rongta', 'HPRT', 'Sewoo', 'Bixolon', 'SRP-', 'Star TSP', 'TSP1', 'Goojprt', 'MTP-'
)

# Returns installed printer names (fast; no WMI). Works on Windows 7/8/10/11.
function Get-InstalledPrinterNames {
    $names = @()
    try {
        Add-Type -AssemblyName System.Drawing -ErrorAction SilentlyContinue
        foreach ($p in [System.Drawing.Printing.PrinterSettings]::InstalledPrinters) { $names += [string]$p }
    } catch {}
    if ($names.Count -eq 0) {
        try { $names = @(Get-Printer -ErrorAction SilentlyContinue | ForEach-Object { $_.Name }) } catch {}
    }
    return $names
}

# Helper to find the thermal printer. Returns $null when none is installed.
function Get-TargetPrinterName {
    param([string]$Preferred)
    $installed = Get-InstalledPrinterNames
    if ($Preferred) {
        foreach ($p in $installed) { if ($p -eq $Preferred) { return $p } }
        foreach ($p in $installed) { if ($p -like "*$Preferred*") { return $p } }
    }
    foreach ($kw in $script:THERMAL_KEYWORDS) {
        foreach ($p in $installed) { if ($p -like "*$kw*") { return $p } }
    }
    return $null
}

# Best-effort check whether the (USB) printer is physically reachable.
# Windows marks unplugged USB printers as "WorkOffline".
function Test-PrinterOnline {
    param([string]$Name)
    if (-not $Name) { return $false }
    try {
        $safe = $Name.Replace("\", "\\").Replace("'", "\'")
        $wp = $null
        if (Get-Command Get-CimInstance -ErrorAction SilentlyContinue) {
            $wp = Get-CimInstance -ClassName Win32_Printer -Filter "Name='$safe'" -ErrorAction SilentlyContinue
        } else {
            $wp = Get-WmiObject -Class Win32_Printer -Filter "Name='$safe'" -ErrorAction SilentlyContinue
        }
        if (-not $wp) { return $false }
        if ($wp.WorkOffline) { return $false }
        # 7 = Offline per Win32_Printer.PrinterStatus
        if ($wp.PrinterStatus -eq 7) { return $false }
        return $true
    } catch { return $true }
}

# Converts ESC/POS bytes into readable text for TEST MODE previews.
function Convert-EscPosToText {
    param([byte[]]$Bytes)
    $sb = New-Object System.Text.StringBuilder
    $enc = [System.Text.Encoding]::GetEncoding("ISO-8859-1")
    $i = 0
    $n = $Bytes.Length
    while ($i -lt $n) {
        $c = $Bytes[$i]
        if ($c -eq 0x1B) {
            $next = if ($i + 1 -lt $n) { $Bytes[$i + 1] } else { 0 }
            if ($next -eq 0x40) { $i += 2 } elseif ($next -eq 0x70) { $i += 5 } else { $i += 3 }
            continue
        }
        if ($c -eq 0x1D) {
            $next = if ($i + 1 -lt $n) { $Bytes[$i + 1] } else { 0 }
            if ($next -eq 0x56) { $i += 4; [void]$sb.Append("`n------------ CUT -------------`n"); continue }
            if ($next -eq 0x6B -and ($i + 3) -lt $n) {
                $len = [int]$Bytes[$i + 3]
                $dataLen = [Math]::Max(0, [Math]::Min($len - 2, $n - ($i + 6)))
                $code = if ($dataLen -gt 0) { $enc.GetString($Bytes, $i + 6, $dataLen) } else { '' }
                [void]$sb.Append("||||| BARCODE: $code |||||")
                $i += 4 + $len
                continue
            }
            $i += 3
            continue
        }
        if ($c -eq 0x10) { $i += 5; continue }
        if ($c -eq 0x07) { $i += 1; continue }
        [void]$sb.Append([char]$c)
        $i++
    }
    return $sb.ToString()
}

# TEST MODE: no thermal printer installed -> save a text preview instead of
# failing, so checkout can be tested on any laptop with zero dialogs.
function Save-ReceiptPreview {
    param([byte[]]$Bytes, [string]$Kind, [string]$Ref)
    try {
        $dir = Join-Path $PSScriptRoot 'receipt-previews'
        if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
        $safeRef = if ($Ref) { ($Ref -replace '[^A-Za-z0-9_-]', '') } else { '' }
        $stamp = (Get-Date).ToString('yyyyMMdd-HHmmss-fff')
        $file = Join-Path $dir ("{0}_{1}{2}.txt" -f $stamp, $Kind, $(if ($safeRef) { "_$safeRef" } else { '' }))
        $text = Convert-EscPosToText -Bytes $Bytes
        [System.IO.File]::WriteAllText($file, $text, [System.Text.Encoding]::UTF8)
        # Keep only the latest 200 previews
        Get-ChildItem $dir -Filter *.txt | Sort-Object LastWriteTime -Descending | Select-Object -Skip 200 | Remove-Item -Force -ErrorAction SilentlyContinue
        return $file
    } catch { return $null }
}

# Sends bytes to the thermal printer, or saves a preview in TEST MODE.
# Returns a hashtable: success, mode (printed|queued|simulated|failed), printer, preview_file
function Send-PosJob {
    param([byte[]]$Bytes, [string]$Kind, [string]$Ref)
    $target = Get-TargetPrinterName -Preferred $PrinterName
    $script:actualPrinter = $target
    if (-not $target) {
        $file = Save-ReceiptPreview -Bytes $Bytes -Kind $Kind -Ref $Ref
        Write-Host "[TEST MODE] No thermal printer installed - preview saved: $file" -ForegroundColor Magenta
        return @{ success = $true; mode = 'simulated'; printer = $null; preview_file = $file }
    }
    $stage = [RawPrinterHelper]::SendBytesToPrinterEx($target, $Bytes, 2500)
    if ($stage -ge 4) { return @{ success = $true; mode = 'printed'; printer = $target } }
    # Stage 1 = printer opened but StartDocPrinter is still blocking (USB unplugged);
    # Windows still queues the job, so it is not a real failure.
    if ($stage -ge 1) {
        Write-Host "[QUEUED] Job is in the Windows spooler for $target (printer offline/unplugged?)" -ForegroundColor Yellow
        return @{ success = $true; mode = 'queued'; printer = $target }
    }
    # Could not even open the printer (driver removed / spooler stopped): save preview so nothing is lost
    $file = Save-ReceiptPreview -Bytes $Bytes -Kind $Kind -Ref $Ref
    Write-Host "[FAILED] Could not open printer $target (stage $stage). Preview saved: $file" -ForegroundColor Red
    return @{ success = $false; mode = 'failed'; printer = $target; preview_file = $file }
}

# Helper to format a 2-column receipt line (e.g. "SUBTOTAL:" and "P25.00") with exact 32-character width
function Format-ReceiptLine {
    param([string]$Left, [string]$Right, [int]$Width = 32)
    $leftClean = if ($Left) { $Left.Trim() } else { "" }
    $rightClean = if ($Right) { $Right.Trim() } else { "" }
    $spaces = $Width - $leftClean.Length - $rightClean.Length
    if ($spaces -lt 1) { $spaces = 1 }
    return $leftClean + (' ' * $spaces) + $rightClean + "`n"
}

# Helper to build ESC/POS binary data for a receipt
function New-EscPosReceipt {
    param([PSCustomObject]$data)
    
    $ms = New-Object System.IO.MemoryStream
    $bw = New-Object System.IO.BinaryWriter($ms)
    $enc = [System.Text.Encoding]::GetEncoding("ISO-8859-1")

    # 1. Initialize printer: ESC @
    $bw.Write([byte[]]@(0x1B, 0x40))

    # 2. Universal Cash drawer kick pulse: Pin 2 + Pin 5 + ASCII + DLE DC4 + BEL
    $bw.Write($script:DRAWER_PULSE)

    # 3. Store Name (Centered, Bold, Double-Height/Width): ESC a 1, ESC E 1, GS ! 0x11
    $bw.Write([byte[]]@(0x1B, 0x61, 0x01)) # Center
    $bw.Write([byte[]]@(0x1B, 0x45, 0x01)) # Bold on
    $bw.Write([byte[]]@(0x1D, 0x21, 0x11)) # Double size
    $shopName = if ($data.shop_name) { $data.shop_name } else { "RE M STORE" }
    $bw.Write($enc.GetBytes("$shopName`n"))
    
    # Normal size & font: GS ! 0x00, ESC E 0
    $bw.Write([byte[]]@(0x1D, 0x21, 0x00))
    $bw.Write([byte[]]@(0x1B, 0x45, 0x00))

    if ($data.shop_address) {
        $bw.Write($enc.GetBytes("$($data.shop_address)`n"))
    }
    if ($data.shop_tin) {
        $bw.Write($enc.GetBytes("TIN: $($data.shop_tin)`n"))
    }

    # Left align: ESC a 0
    $bw.Write([byte[]]@(0x1B, 0x61, 0x00))
    $bw.Write($enc.GetBytes("OR#: $($data.ref)`n"))
    $bw.Write($enc.GetBytes("--------------------------------`n"))
    $bw.Write($enc.GetBytes("CASHIER: $($data.cashier)`n"))
    $bw.Write($enc.GetBytes("TERM: $($data.terminal_id)  $($data.date_time)`n"))
    $bw.Write($enc.GetBytes("--------------------------------`n"))
    $bw.Write($enc.GetBytes("QTY  ITEM DESCRIPTION   PRICE    TOTAL`n"))
    $bw.Write($enc.GetBytes("--------------------------------`n"))

    # Items
    if ($data.items) {
        foreach ($it in $data.items) {
            $qtyVal = if ($it.qty) { [double]$it.qty } else { 1.0 }
            $priceVal = if ($it.price) { [double]$it.price } else { 0.0 }
            $qtyStr = "$qtyVal x"
            $nameStr = "$($it.name)"
            if ($nameStr.Length -gt 16) { $nameStr = $nameStr.Substring(0, 16) }
            $priceStr = [string]::Format("{0:N2}", $priceVal)
            $totalStr = [string]::Format("{0:N2}", ($qtyVal * $priceVal))
            
            # Format: "1 x  Item Name       25.00  25.00"
            $line = "{0,-4} {1,-14} {2,6} {3,6}`n" -f $qtyStr, $nameStr, $priceStr, $totalStr
            $bw.Write($enc.GetBytes($line))
        }
    }

    $bw.Write($enc.GetBytes("--------------------------------`n"))

    # Safe double converter
    $toNum = {
        param($v)
        if ($null -eq $v -or "$v".Trim() -eq "") { return 0.0 }
        $d = 0.0
        if ([double]::TryParse("$v", [System.Globalization.NumberStyles]::Any, [System.Globalization.CultureInfo]::InvariantCulture, [ref]$d)) { return $d }
        if ([double]::TryParse("$v", [ref]$d)) { return $d }
        return 0.0
    }

    # Subtotals
    $cur = if ($data.currency) { $data.currency } else { "P" }
    $subtotal = [string]::Format("{0}{1:N2}", $cur, (& $toNum $data.subtotal))
    $vatVal = & $toNum $data.vat
    $taxVal = & $toNum $data.tax
    $vatRate = & $toNum $data.vat_rate
    $taxRate = & $toNum $data.tax_rate
    $vat = [string]::Format("{0}{1:N2}", $cur, $vatVal)
    $tax = [string]::Format("{0}{1:N2}", $cur, $taxVal)
    $total = [string]::Format("{0}{1:N2}", $cur, (& $toNum $data.total))
    $cash = [string]::Format("{0}{1:N2}", $cur, (& $toNum $data.cash))
    $change = [string]::Format("{0}{1:N2}", $cur, (& $toNum $data.change))
    $itemCount = if ($data.item_count) { "$($data.item_count)" } else { "1" }

    $bw.Write($enc.GetBytes((Format-ReceiptLine "SUBTOTAL:" $subtotal)))
    $bw.Write($enc.GetBytes((Format-ReceiptLine "VAT ($vatRate%):" $vat)))
    $bw.Write($enc.GetBytes((Format-ReceiptLine "TAX ($taxRate%):" $tax)))
    
    # TOTAL DUE (Bold, double height)
    $bw.Write([byte[]]@(0x1B, 0x45, 0x01)) # Bold on
    $bw.Write([byte[]]@(0x1D, 0x21, 0x01)) # Double height
    $bw.Write($enc.GetBytes((Format-ReceiptLine "TOTAL DUE:" $total 32)))
    $bw.Write([byte[]]@(0x1D, 0x21, 0x00)) # Normal height
    $bw.Write([byte[]]@(0x1B, 0x45, 0x00)) # Bold off

    $bw.Write($enc.GetBytes("--------------------------------`n"))
    $bw.Write($enc.GetBytes("PAYMENT: CASH`n"))
    $bw.Write($enc.GetBytes((Format-ReceiptLine "CASH TENDERED:" $cash)))
    $bw.Write($enc.GetBytes((Format-ReceiptLine "CHANGE DUE:" $change)))
    $bw.Write($enc.GetBytes((Format-ReceiptLine "ITEMS:" $itemCount)))
    $bw.Write($enc.GetBytes("--------------------------------`n"))

    # Center align footer: ESC a 1
    $bw.Write([byte[]]@(0x1B, 0x61, 0x01))
    $bw.Write($enc.GetBytes("Thank You for Shopping!`n"))
    $bw.Write($enc.GetBytes("Please keep receipt for returns.`n`n"))

    # CODE128 Barcode for clean order ref
    $cleanRef = if ($data.ref) { ($data.ref -split ' \(')[0] } else { "" }
    if ($cleanRef -ne "") {
        # Barcode dimensions: GS h 48 (height), GS w 2 (width)
        $bw.Write([byte[]]@(0x1D, 0x68, 48))
        $bw.Write([byte[]]@(0x1D, 0x77, 2))
        $bw.Write([byte[]]@(0x1D, 0x48, 2)) # Text below barcode
        
        # GS k 73 len {B cleanRef (CODE128)
        $codeBytes = $enc.GetBytes($cleanRef)
        $barcodeData = [System.Collections.Generic.List[byte]]::new()
        $barcodeData.Add(0x7B) # Code Set B
        $barcodeData.Add(0x42) # 'B'
        foreach ($b in $codeBytes) { $barcodeData.Add($b) }
        
        $bw.Write([byte[]]@(0x1D, 0x6B, 73, [byte]$barcodeData.Count))
        $bw.Write($barcodeData.ToArray())
        $bw.Write($enc.GetBytes("`nScan to void this order`n"))
    }

    # Feed 4 lines and partial cut: ESC d 4, GS V 66 0
    $bw.Write([byte[]]@(0x1B, 0x64, 4))
    $bw.Write([byte[]]@(0x1D, 0x56, 66, 0))

    $bw.Flush()
    $result = $ms.ToArray()
    $bw.Close()
    $ms.Close()
    return $result
}
Set-Alias -Name Build-EscPosReceipt -Value New-EscPosReceipt

# Helper to build ESC/POS binary data for a Shift Start / Cash Float Slip
function New-EscPosShiftStart {
    param([PSCustomObject]$data)

    $ms = New-Object System.IO.MemoryStream
    $bw = New-Object System.IO.BinaryWriter($ms)
    $enc = [System.Text.Encoding]::GetEncoding("ISO-8859-1")

    # 1. Initialize printer: ESC @
    $bw.Write([byte[]]@(0x1B, 0x40))

    # 2. Store Header (Centered)
    $bw.Write([byte[]]@(0x1B, 0x61, 0x01)) # Center
    $bw.Write([byte[]]@(0x1B, 0x45, 0x01)) # Bold on
    $bw.Write([byte[]]@(0x1D, 0x21, 0x11)) # Double size
    $shopName = if ($data.shop_name) { $data.shop_name } else { "RE M STORE" }
    $bw.Write($enc.GetBytes("$shopName`n"))
    $bw.Write([byte[]]@(0x1D, 0x21, 0x00)) # Normal size
    $bw.Write([byte[]]@(0x1B, 0x45, 0x00)) # Bold off

    if ($data.shop_address) { $bw.Write($enc.GetBytes("$($data.shop_address)`n")) }
    if ($data.shop_tin) { $bw.Write($enc.GetBytes("TIN: $($data.shop_tin)`n")) }

    $bw.Write($enc.GetBytes("--------------------------------`n"))
    $bw.Write([byte[]]@(0x1B, 0x45, 0x01))
    $bw.Write($enc.GetBytes("*** SHIFT START / CASH FLOAT ***`n"))
    $bw.Write([byte[]]@(0x1B, 0x45, 0x00))
    $bw.Write($enc.GetBytes("--------------------------------`n"))

    # Left align
    $bw.Write([byte[]]@(0x1B, 0x61, 0x00))
    $cashier = if ($data.cashier) { $data.cashier } else { "Cashier" }
    $role = if ($data.role) { $data.role } else { "Staff" }
    $dateTime = if ($data.date_time) { $data.date_time } else { (Get-Date).ToString("yyyy-MM-dd HH:mm:ss") }
    $bw.Write($enc.GetBytes("CASHIER: $cashier`n"))
    $bw.Write($enc.GetBytes("ROLE:    $role`n"))
    $bw.Write($enc.GetBytes("TIME:    $dateTime`n"))
    if ($data.shift_id) { $bw.Write($enc.GetBytes("SHIFT ID:#$($data.shift_id)`n")) }
    $bw.Write($enc.GetBytes("--------------------------------`n"))
    $bw.Write($enc.GetBytes("OPENING FLOAT BREAKDOWN:`n"))

    $labels = @{
        "b1000" = "P1,000"; "b500" = "P500"; "b200" = "P200"; "b100" = "P100"; "b50" = "P50"; "b20" = "P20";
        "c20" = "P20 coin"; "c10" = "P10 coin"; "c5" = "P5 coin"; "c1" = "P1 coin"; "c025" = "25c coin"
    }
    $values = @{
        "b1000" = 1000.0; "b500" = 500.0; "b200" = 200.0; "b100" = 100.0; "b50" = 50.0; "b20" = 20.0;
        "c20" = 20.0; "c10" = 10.0; "c5" = 5.0; "c1" = 1.0; "c025" = 0.25
    }

    if ($data.denoms) {
        foreach ($prop in $data.denoms.PSObject.Properties) {
            $k = $prop.Name
            $qty = [int]($prop.Value)
            if ($qty -gt 0) {
                $lbl = if ($labels.ContainsKey($k)) { $labels[$k] } else { $k }
                $unitVal = if ($values.ContainsKey($k)) { $values[$k] } else { 0.0 }
                $sub = $qty * $unitVal
                $left = "$lbl x $qty"
                $right = [string]::Format("P{0:N2}", $sub)
                $bw.Write($enc.GetBytes((Format-ReceiptLine $left $right)))
            }
        }
    }

    $bw.Write($enc.GetBytes("--------------------------------`n"))

    # Total Float
    $cur = if ($data.currency) { $data.currency } else { "P" }
    $totVal = if ($data.total) { [double]$data.total } else { 0.0 }
    $totStr = [string]::Format("{0}{1:N2}", $cur, $totVal)

    $bw.Write([byte[]]@(0x1B, 0x45, 0x01)) # Bold on
    $bw.Write([byte[]]@(0x1D, 0x21, 0x01)) # Double height
    $bw.Write($enc.GetBytes((Format-ReceiptLine "STARTING FLOAT:" $totStr 32)))
    $bw.Write([byte[]]@(0x1D, 0x21, 0x00))
    $bw.Write([byte[]]@(0x1B, 0x45, 0x00))
    $bw.Write($enc.GetBytes("--------------------------------`n"))

    # Center footer & signatures
    $bw.Write([byte[]]@(0x1B, 0x61, 0x01)) # Center
    $bw.Write($enc.GetBytes("Cashier Signature:`n`n"))
    $bw.Write($enc.GetBytes("________________________________`n"))
    $bw.Write($enc.GetBytes("$cashier`n`n"))
    $bw.Write($enc.GetBytes("Drawer Verified & Accepted`n"))
    $bw.Write($enc.GetBytes("Keep slip in drawer until close`n`n"))

    # Feed 4 lines and cut
    $bw.Write([byte[]]@(0x1B, 0x64, 4))
    $bw.Write([byte[]]@(0x1D, 0x56, 66, 0))

    $bw.Flush()
    $result = $ms.ToArray()
    $bw.Close()
    $ms.Close()
    return $result
}
Set-Alias -Name Build-EscPosShiftStart -Value New-EscPosShiftStart

# Helper to build ESC/POS binary data for an End of Shift Z-Reading Summary
function New-EscPosShiftSummary {
    param([PSCustomObject]$data)

    $ms = New-Object System.IO.MemoryStream
    $bw = New-Object System.IO.BinaryWriter($ms)
    $enc = [System.Text.Encoding]::GetEncoding("ISO-8859-1")

    # 1. Initialize printer: ESC @
    $bw.Write([byte[]]@(0x1B, 0x40))

    # 2. Store Header (Centered)
    $bw.Write([byte[]]@(0x1B, 0x61, 0x01)) # Center
    $bw.Write([byte[]]@(0x1B, 0x45, 0x01)) # Bold on
    $bw.Write([byte[]]@(0x1D, 0x21, 0x11)) # Double size
    $shopName = if ($data.shop_name) { $data.shop_name } else { "RE M STORE" }
    $bw.Write($enc.GetBytes("$shopName`n"))
    $bw.Write([byte[]]@(0x1D, 0x21, 0x00)) # Normal size
    $bw.Write([byte[]]@(0x1B, 0x45, 0x00)) # Bold off

    if ($data.shop_address) { $bw.Write($enc.GetBytes("$($data.shop_address)`n")) }
    if ($data.shop_tin) { $bw.Write($enc.GetBytes("TIN: $($data.shop_tin)`n")) }

    $bw.Write($enc.GetBytes("--------------------------------`n"))
    $bw.Write([byte[]]@(0x1B, 0x45, 0x01))
    $bw.Write($enc.GetBytes("*** SHIFT SUMMARY - Z-READ ***`n"))
    $bw.Write([byte[]]@(0x1B, 0x45, 0x00))
    $bw.Write($enc.GetBytes("--------------------------------`n"))

    # Left align
    $bw.Write([byte[]]@(0x1B, 0x61, 0x00))
    $cashier = if ($data.cashier) { $data.cashier } else { "Cashier" }
    $bw.Write($enc.GetBytes("CASHIER: $cashier`n"))
    $bw.Write($enc.GetBytes("LOGIN:   $($data.login_time)`n"))
    $bw.Write($enc.GetBytes("LOGOUT:  $($data.logout_time)`n"))
    $bw.Write($enc.GetBytes("--------------------------------`n"))

    $cur = "P"
    $toNum = {
        param($v)
        if ($null -eq $v -or "$v".Trim() -eq "") { return 0.0 }
        $d = 0.0
        if ([double]::TryParse("$v", [System.Globalization.NumberStyles]::Any, [System.Globalization.CultureInfo]::InvariantCulture, [ref]$d)) { return $d }
        if ([double]::TryParse("$v", [ref]$d)) { return $d }
        return 0.0
    }

    $openFloat = [string]::Format("{0}{1:N2}", $cur, (& $toNum $data.opening_float))
    $cashSales = [string]::Format("{0}{1:N2}", $cur, (& $toNum $data.cash_sales))
    $expected = [string]::Format("{0}{1:N2}", $cur, (& $toNum $data.expected_cash))
    $actual = [string]::Format("{0}{1:N2}", $cur, (& $toNum $data.closing_cash))
    $varVal = & $toNum $data.variance
    $varLabel = if ($varVal -lt 0) { "SHORT" } elseif ($varVal -gt 0) { "OVER" } else { "EXACT" }
    $varPrefix = if ($varVal -gt 0) { "+" } else { "" }
    $varStr = [string]::Format("{0}{1}{2:N2} ({3})", $varPrefix, $cur, $varVal, $varLabel)

    $bw.Write($enc.GetBytes((Format-ReceiptLine "OPENING FLOAT:" $openFloat)))
    $bw.Write($enc.GetBytes((Format-ReceiptLine "GROSS CASH SALES:" $cashSales)))

    $voidCount = if ($data.void_count) { [int]$data.void_count } else { 0 }
    if ($voidCount -gt 0) {
        $voidValStr = [string]::Format("-{0}{1:N2}", $cur, (& $toNum $data.void_value))
        $bw.Write($enc.GetBytes((Format-ReceiptLine "VOIDS/REFUNDS ($voidCount):" $voidValStr)))
    }

    $bw.Write($enc.GetBytes("--------------------------------`n"))
    $bw.Write($enc.GetBytes((Format-ReceiptLine "EXPECTED IN TILL:" $expected)))
    $bw.Write($enc.GetBytes((Format-ReceiptLine "ACTUAL COUNTED:" $actual)))
    $bw.Write($enc.GetBytes("--------------------------------`n"))

    # VARIANCE (Bold, double height)
    $bw.Write([byte[]]@(0x1B, 0x45, 0x01)) # Bold on
    $bw.Write([byte[]]@(0x1D, 0x21, 0x01)) # Double height
    $bw.Write($enc.GetBytes((Format-ReceiptLine "VARIANCE:" $varStr 32)))
    $bw.Write([byte[]]@(0x1D, 0x21, 0x00))
    $bw.Write([byte[]]@(0x1B, 0x45, 0x00))
    $bw.Write($enc.GetBytes("--------------------------------`n"))

    $txCount = if ($data.transaction_count) { "$($data.transaction_count)" } else { "0" }
    $itCount = if ($data.items_sold) { "$($data.items_sold)" } else { "0" }
    $bw.Write($enc.GetBytes((Format-ReceiptLine "TOTAL TRANSACTIONS:" $txCount)))
    $bw.Write($enc.GetBytes((Format-ReceiptLine "TOTAL ITEMS SOLD:" $itCount)))

    # Top Items
    if ($data.top_items -and $data.top_items.Count -gt 0) {
        $bw.Write($enc.GetBytes("--------------------------------`n"))
        $bw.Write($enc.GetBytes("TOP SELLING ITEMS:`n"))
        foreach ($ti in $data.top_items) {
            $name = if ($ti.name) { $ti.name } else { $ti.product_name }
            if ($name.Length -gt 16) { $name = $name.Substring(0, 16) }
            $lineLeft = "$name x$($ti.qty)"
            $subVal = if ($ti.total) { $ti.total } else { $ti.revenue }
            $lineRight = [string]::Format("P{0:N2}", (& $toNum $subVal))
            $bw.Write($enc.GetBytes((Format-ReceiptLine $lineLeft $lineRight)))
        }
    }

    $bw.Write($enc.GetBytes("--------------------------------`n"))
    # Signatures
    $bw.Write([byte[]]@(0x1B, 0x61, 0x01)) # Center
    $bw.Write($enc.GetBytes("Cashier Signature:`n`n"))
    $bw.Write($enc.GetBytes("________________________________`n"))
    $bw.Write($enc.GetBytes("$cashier`n`n"))
    $bw.Write($enc.GetBytes("Manager / Auditor Signature:`n`n"))
    $bw.Write($enc.GetBytes("________________________________`n`n"))
    $bw.Write($enc.GetBytes("End of Shift Audit Tape`n"))
    $bw.Write($enc.GetBytes("Retain for Bookkeeping`n`n"))

    # Feed 4 lines and cut
    $bw.Write([byte[]]@(0x1B, 0x64, 4))
    $bw.Write([byte[]]@(0x1D, 0x56, 66, 0))

    $bw.Flush()
    $result = $ms.ToArray()
    $bw.Close()
    $ms.Close()
    return $result
}
Set-Alias -Name Build-EscPosShiftSummary -Value New-EscPosShiftSummary

# Helper to build ESC/POS binary data for a Void Receipt slip
function New-EscPosVoidReceipt {
    param([PSCustomObject]$data)

    $ms = New-Object System.IO.MemoryStream
    $bw = New-Object System.IO.BinaryWriter($ms)
    $enc = [System.Text.Encoding]::GetEncoding("ISO-8859-1")

    # Initialize printer
    $bw.Write([byte[]]@(0x1B, 0x40))

    # Header (Centered)
    $bw.Write([byte[]]@(0x1B, 0x61, 0x01)) # Center
    $bw.Write([byte[]]@(0x1B, 0x45, 0x01)) # Bold on
    $bw.Write([byte[]]@(0x1D, 0x21, 0x11)) # Double size
    $shopName = if ($data.shop_name) { $data.shop_name } else { "RE M STORE" }
    $bw.Write($enc.GetBytes("$shopName`n"))
    $bw.Write([byte[]]@(0x1D, 0x21, 0x00)) # Normal size
    $bw.Write([byte[]]@(0x1B, 0x45, 0x00)) # Bold off

    if ($data.shop_address) { $bw.Write($enc.GetBytes("$($data.shop_address)`n")) }
    if ($data.shop_tin)     { $bw.Write($enc.GetBytes("TIN: $($data.shop_tin)`n")) }

    $bw.Write($enc.GetBytes("--------------------------------`n"))
    $bw.Write([byte[]]@(0x1B, 0x45, 0x01))
    $isFullVoid = $data.is_full_void -eq $true
    $header = if ($isFullVoid) { "** VOID RECEIPT - ORDER VOIDED **" } else { "** VOID RECEIPT - ITEM(S) VOIDED **" }
    $bw.Write($enc.GetBytes("$header`n"))
    $bw.Write([byte[]]@(0x1B, 0x45, 0x00))
    $bw.Write($enc.GetBytes("--------------------------------`n"))

    # Left align
    $bw.Write([byte[]]@(0x1B, 0x61, 0x00))
    $bw.Write($enc.GetBytes("OR#:     $($data.order_ref)`n"))
    $bw.Write($enc.GetBytes("CASHIER: $($data.cashier)`n"))
    $bw.Write($enc.GetBytes("DATE:    $($data.date_time)`n"))
    if ($data.reason) { $bw.Write($enc.GetBytes("REASON:  $($data.reason)`n")) }
    $bw.Write($enc.GetBytes("--------------------------------`n"))
    $bw.Write($enc.GetBytes("QTY  ITEM DESCRIPTION   PRICE    TOTAL`n"))
    $bw.Write($enc.GetBytes("--------------------------------`n"))

    $toNum = {
        param($v)
        if ($null -eq $v -or "$v".Trim() -eq "") { return 0.0 }
        $d = 0.0
        if ([double]::TryParse("$v", [System.Globalization.NumberStyles]::Any, [System.Globalization.CultureInfo]::InvariantCulture, [ref]$d)) { return $d }
        return 0.0
    }

    if ($data.items) {
        foreach ($it in $data.items) {
            $qty       = [int](& $toNum $it.quantity)
            $voidedQty = [int](& $toNum $it.voided_qty)
            $remaining = $qty - $voidedQty
            $price     = & $toNum $it.price
            $priceFmt  = [string]::Format("{0:N2}", $price)

            if ($remaining -gt 0) {
                $name = if ($it.product_name) { $it.product_name } else { "Item" }
                if ($name.Length -gt 14) { $name = $name.Substring(0, 14) }
                $tot = [string]::Format("{0:N2}", ($remaining * $price))
                $line = "{0,-4} {1,-14} {2,6} {3,6}`n" -f "$remaining x", $name, $priceFmt, $tot
                $bw.Write($enc.GetBytes($line))
            }
            if ($voidedQty -gt 0) {
                $name = if ($it.product_name) { $it.product_name } else { "Item" }
                if ($name.Length -gt 10) { $name = $name.Substring(0, 10) }
                $tot = [string]::Format("{0:N2}", ($voidedQty * $price))
                $line = "{0,-4} {1,-10}VOID {2,6} {3,6}`n" -f "$voidedQty x", $name, $priceFmt, $tot
                $bw.Write($enc.GetBytes($line))
            }
        }
    }

    $bw.Write($enc.GetBytes("--------------------------------`n"))

    $cur = if ($data.currency) { $data.currency } else { "P" }
    $voidedAmt = & $toNum $data.voided_amount
    $netTotal  = & $toNum $data.net_total

    $bw.Write([byte[]]@(0x1B, 0x45, 0x01)) # Bold
    $bw.Write($enc.GetBytes((Format-ReceiptLine "VOIDED / REFUNDED:" ([string]::Format("-{0}{1:N2}", $cur, $voidedAmt)))))
    $bw.Write([byte[]]@(0x1D, 0x21, 0x01)) # Double height
    $label = if ($isFullVoid) { "NEW TOTAL:" } else { "UPDATED TOTAL:" }
    $bw.Write($enc.GetBytes((Format-ReceiptLine $label ([string]::Format("{0}{1:N2}", $cur, $netTotal)) 32)))
    $bw.Write([byte[]]@(0x1D, 0x21, 0x00))
    $bw.Write([byte[]]@(0x1B, 0x45, 0x00))
    $bw.Write($enc.GetBytes("--------------------------------`n"))

    # Signature lines
    $bw.Write([byte[]]@(0x1B, 0x61, 0x01)) # Center
    $bw.Write($enc.GetBytes("Customer Signature:`n`n"))
    $bw.Write($enc.GetBytes("________________________________`n`n"))
    $bw.Write($enc.GetBytes("Manager / Owner Authorization:`n`n"))
    $bw.Write($enc.GetBytes("________________________________`n`n"))
    $bw.Write($enc.GetBytes("TRANSACTION VOID AUDIT SLIP`n"))
    $bw.Write($enc.GetBytes("Please keep receipt for audit.`n`n"))

    # Feed and cut
    $bw.Write([byte[]]@(0x1B, 0x64, 4))
    $bw.Write([byte[]]@(0x1D, 0x56, 66, 0))

    $bw.Flush()
    $result = $ms.ToArray()
    $bw.Close()
    $ms.Close()
    return $result
}
Set-Alias -Name Build-EscPosVoidReceipt -Value New-EscPosVoidReceipt


# Start HTTP Listener
$listener = New-Object System.Net.HttpListener
$prefix = "http://127.0.0.1:$Port/"
$listener.Prefixes.Add($prefix)
# Also answer on http://localhost (some browsers resolve it to ::1 first)
try { $listener.Prefixes.Add("http://localhost:$Port/") } catch {}

try {
    $listener.Start()
    Write-Host "============================================================" -ForegroundColor Cyan
    Write-Host " POS Native Print Agent Running on $prefix" -ForegroundColor Green
    Write-Host " Direct silent printing to $PrinterName" -ForegroundColor Yellow
    Write-Host "============================================================" -ForegroundColor Cyan
} catch {
    # Retry with only 127.0.0.1 (the localhost prefix can need URL ACL on some PCs)
    try {
        $listener = New-Object System.Net.HttpListener
        $listener.Prefixes.Add($prefix)
        $listener.Start()
        Write-Host " POS Native Print Agent Running on $prefix" -ForegroundColor Green
    } catch {
        Write-Host "ERROR: Could not start listener on port $Port : $_" -ForegroundColor Red
        exit 1
    }
}

$script:actualPrinter = Get-TargetPrinterName -Preferred $PrinterName
if ($script:actualPrinter) {
    Write-Host "Target Printer: $script:actualPrinter" -ForegroundColor Green
} else {
    Write-Host "No thermal printer installed -> TEST MODE (receipts saved to .\receipt-previews)" -ForegroundColor Magenta
}

while ($listener.IsListening) {
    $context = $null
    $res = $null
    try {
        $context = $listener.GetContext()
        $req = $context.Request
        $res = $context.Response

        # Enable complete CORS & Private Network Access for POS app (works for localhost and cloud https://pos-system-9f0n.onrender.com)
        $origin = $req.Headers["Origin"]
        if (-not $origin) { $origin = "*" }
        $res.AddHeader("Access-Control-Allow-Origin", $origin)
        $res.AddHeader("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        $res.AddHeader("Access-Control-Allow-Headers", "Content-Type, X-Requested-With, Origin, Accept")
        $res.AddHeader("Access-Control-Allow-Credentials", "true")
        $res.AddHeader("Access-Control-Allow-Private-Network", "true")

        if ($req.HttpMethod -eq "OPTIONS") {
            $res.StatusCode = 204
            $res.Close()
            continue
        }

        if ($req.Url.AbsolutePath -eq "/status") {
            $script:actualPrinter = Get-TargetPrinterName -Preferred $PrinterName
            $statusObj = @{
                status = "online"
                printer = $script:actualPrinter
                printer_online = (Test-PrinterOnline -Name $script:actualPrinter)
                test_mode = (-not $script:actualPrinter)
                time = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
            }
            $json = ConvertTo-Json $statusObj
            $buf = [System.Text.Encoding]::UTF8.GetBytes($json)
            $res.ContentType = "application/json"
            $res.ContentLength64 = $buf.Length
            $res.OutputStream.Write($buf, 0, $buf.Length)
            $res.Close()
            continue
        }

        # Drawer kick endpoint - Instant hardware pulse with universal pin coverage
        if ($req.HttpMethod -eq "POST" -and $req.Url.AbsolutePath -eq "/drawer") {
            try {
                if ($req.HasEntityBody) {
                    $reader = New-Object System.IO.StreamReader($req.InputStream, $req.ContentEncoding)
                    $null = $reader.ReadToEnd()
                    $reader.Close()
                }
            } catch {}
            $target = Get-TargetPrinterName -Preferred $PrinterName
            $script:actualPrinter = $target
            $mode = 'simulated'
            $printSuccess = $true
            if ($target) {
                $stage = [RawPrinterHelper]::SendBytesToPrinterEx($target, $script:DRAWER_PULSE, 1500)
                $printSuccess = ($stage -ge 1)
                $mode = if ($stage -ge 4) { 'printed' } elseif ($stage -ge 1) { 'queued' } else { 'failed' }
            }
            Write-Host "[DRAWER] Cash drawer kick pulse -> Printer: $target (Mode: $mode)" -ForegroundColor Cyan
            $respObj = @{
                success = $printSuccess
                printer = $target
                mode = $mode
                action = "drawer_kick"
            }
            $json = ConvertTo-Json $respObj
            $buf = [System.Text.Encoding]::UTF8.GetBytes($json)
            $res.ContentType = "application/json"
            $res.ContentLength64 = $buf.Length
            $res.OutputStream.Write($buf, 0, $buf.Length)
            $res.Close()
            continue
        }

        if ($req.HttpMethod -eq "POST" -and $req.Url.AbsolutePath -eq "/print") {
            $reader = New-Object System.IO.StreamReader($req.InputStream, $req.ContentEncoding)
            $body = $reader.ReadToEnd()
            $reader.Close()

            $data = ConvertFrom-Json $body
            # Peso sign is not in the printer code page -> print as "P"
            if ($data.currency -and "$($data.currency)" -eq [string][char]0x20B1) { $data.currency = 'P' }

            $printType = if ($data.type) { "$($data.type)" } else { "sale" }
            $jobRef = if ($data.ref) { "$($data.ref)" } elseif ($data.order_ref) { "$($data.order_ref)" } else { '' }
            if ($printType -eq "shift_start") {
                Write-Host "[PRINT] Shift Start / Cash Float Slip" -ForegroundColor Green
                $escPosBytes = New-EscPosShiftStart -data $data
            } elseif ($printType -eq "shift_summary" -or $printType -eq "z_read") {
                Write-Host "[PRINT] Shift Summary / Z-Reading" -ForegroundColor Green
                $escPosBytes = New-EscPosShiftSummary -data $data
            } elseif ($printType -eq "void_receipt") {
                Write-Host "[PRINT] Void Receipt (Ref: $jobRef)" -ForegroundColor Yellow
                $escPosBytes = New-EscPosVoidReceipt -data $data
            } else {
                Write-Host "[PRINT] Sale receipt (Ref: $jobRef)" -ForegroundColor Green
                $escPosBytes = New-EscPosReceipt -data $data
            }

            $job = Send-PosJob -Bytes $escPosBytes -Kind $printType -Ref $jobRef

            $respObj = @{
                success = $job.success
                mode = $job.mode
                printer = $job.printer
                preview_file = $job.preview_file
                type = $printType
                order_ref = $jobRef
            }
            $json = ConvertTo-Json $respObj
            $buf = [System.Text.Encoding]::UTF8.GetBytes($json)
            $res.ContentType = "application/json"
            $res.ContentLength64 = $buf.Length
            $res.OutputStream.Write($buf, 0, $buf.Length)
            $res.Close()
            continue
        }

        # 404 for unknown endpoints - always properly close connection
        $res.StatusCode = 404
        $notFoundObj = @{ success = $false; error = "Endpoint not found" }
        $buf = [System.Text.Encoding]::UTF8.GetBytes((ConvertTo-Json $notFoundObj))
        $res.ContentType = "application/json"
        $res.ContentLength64 = $buf.Length
        $res.OutputStream.Write($buf, 0, $buf.Length)
        $res.Close()
    } catch {
        Write-Host "Error processing request: $_" -ForegroundColor Red
        if ($res) {
            try {
                $res.StatusCode = 500
                $errObj = @{ success = $false; error = "$_" }
                $buf = [System.Text.Encoding]::UTF8.GetBytes((ConvertTo-Json $errObj))
                $res.ContentType = "application/json"
                $res.ContentLength64 = $buf.Length
                $res.OutputStream.Write($buf, 0, $buf.Length)
                $res.Close()
            } catch {}
        }
    } finally {
        if ($res) {
            try { $res.Close() } catch {}
        }
    }
}
