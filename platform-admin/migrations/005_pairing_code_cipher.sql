-- ProCast Platform Super Admin — migration 005 (PostgreSQL ONLY)
-- Lets a super admin re-read and copy a LIVE activation code from the admin UI
-- when the email never arrives.
--
-- Until now only the SHA-256 hash was kept, so a code could be read exactly
-- once: in the flash banner shown immediately after issuing it. Miss that page
-- and the admin had no choice but to revoke and re-issue, even though the code
-- was still perfectly valid and unexpired — which matters a lot here, because
-- the email is only a convenience and the admin is the real fallback channel.
--
-- The code is therefore ALSO stored as AES-256-GCM ciphertext, using the same
-- PLATFORM_ENC_KEY envelope already used for TOTP seeds. The admin UI decrypts
-- it on demand. The hash remains the source of truth for redemption, so the
-- ciphertext is never accepted by the redemption endpoint — leaking this column
-- on its own does not let anyone redeem a code.
--
-- Exposure is bounded: the column is only populated for codes that are still
-- live, is nulled the moment a code is redeemed/revoked/expired-out, expires on
-- a short TTL (PAIRING_CODE_TTL, default 24h), is single-use, and every reveal
-- is written to the audit log.

ALTER TABLE platform_user_pairings  ADD COLUMN IF NOT EXISTS code_cipher TEXT NULL;
ALTER TABLE platform_store_pairings ADD COLUMN IF NOT EXISTS code_cipher TEXT NULL;

COMMENT ON COLUMN platform_user_pairings.code_cipher  IS 'AES-256-GCM ciphertext of the 6-digit code, for admin re-copy only. code_hash stays authoritative for redemption.';
COMMENT ON COLUMN platform_store_pairings.code_cipher IS 'AES-256-GCM ciphertext of the 6-digit code, for admin re-copy only. code_hash stays authoritative for redemption.';
