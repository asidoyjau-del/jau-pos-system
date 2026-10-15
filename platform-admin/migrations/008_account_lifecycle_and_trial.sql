-- ProCast Platform Super Admin — migration 008 (PostgreSQL ONLY)
-- Account Lifecycle, 7-Day Trial, Staff Approval & 6-Digit Passcode Verification
--
-- Adds per-user lifecycle statuses:
--   'PENDING'  : Newly added staff waiting for Super Admin approval
--   'TRIAL'    : Newly registered Store Owners on 7-day trial
--   'ACTIVE'   : Approved/verified accounts in good standing
--   'LOCKED'   : Trial expired accounts awaiting 6-digit Super Admin unlock code
--   'REJECTED' : Staff accounts rejected by Super Admin

ALTER TABLE users ADD COLUMN IF NOT EXISTS account_status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE';
ALTER TABLE users ADD COLUMN IF NOT EXISTS trial_ends_at TIMESTAMPTZ NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS activation_code VARCHAR(64) NULL;         -- SHA-256 hash of 6-digit passcode
ALTER TABLE users ADD COLUMN IF NOT EXISTS activation_code_cipher TEXT NULL;        -- Sealed/encrypted copy for Super Admin reveal
ALTER TABLE users ADD COLUMN IF NOT EXISTS code_expires_at TIMESTAMPTZ NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_pending_email_at TIMESTAMPTZ NULL;  -- 24h rate-limit timestamp for pending notices
ALTER TABLE users ADD COLUMN IF NOT EXISTS rejection_reason TEXT NULL;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'users_account_status_chk') THEN
        ALTER TABLE users ADD CONSTRAINT users_account_status_chk
            CHECK (account_status IN ('PENDING', 'TRIAL', 'ACTIVE', 'LOCKED', 'REJECTED'));
    END IF;
END $$;

-- Grandfather existing users so existing operations are uninterrupted
UPDATE users
   SET account_status = 'ACTIVE'
 WHERE account_status IS NULL OR account_status = '';

CREATE INDEX IF NOT EXISTS idx_users_account_status ON users (account_status);
CREATE INDEX IF NOT EXISTS idx_users_trial_status ON users (account_status, trial_ends_at);
CREATE INDEX IF NOT EXISTS idx_users_activation_code ON users (activation_code) WHERE activation_code IS NOT NULL;
