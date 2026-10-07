-- ProCast Platform Super Admin — migration 002 (PostgreSQL ONLY)
-- Store lifecycle columns on the existing `stores` table.
-- EXISTING stores are backfilled to 'active' so nobody is locked out on deploy.

ALTER TABLE stores ADD COLUMN IF NOT EXISTS status              VARCHAR(20)  NOT NULL DEFAULT 'active';
ALTER TABLE stores ADD COLUMN IF NOT EXISTS rejection_reason    TEXT NULL;
ALTER TABLE stores ADD COLUMN IF NOT EXISTS approved_by         BIGINT NULL;
ALTER TABLE stores ADD COLUMN IF NOT EXISTS approved_at         TIMESTAMPTZ NULL;
ALTER TABLE stores ADD COLUMN IF NOT EXISTS subscription_tier   VARCHAR(20)  NOT NULL DEFAULT 'standard';
ALTER TABLE stores ADD COLUMN IF NOT EXISTS database_sync_token VARCHAR(64) NULL;   -- SHA-256 of the issued API key
ALTER TABLE stores ADD COLUMN IF NOT EXISTS owner_name          VARCHAR(120) NULL;
ALTER TABLE stores ADD COLUMN IF NOT EXISTS owner_email         VARCHAR(190) NULL;
ALTER TABLE stores ADD COLUMN IF NOT EXISTS contact_phone       VARCHAR(40)  NULL;
ALTER TABLE stores ADD COLUMN IF NOT EXISTS verification_doc    VARCHAR(80)  NULL;   -- server-generated filename only
ALTER TABLE stores ADD COLUMN IF NOT EXISTS registered_at       TIMESTAMPTZ NULL;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'stores_status_chk') THEN
        ALTER TABLE stores ADD CONSTRAINT stores_status_chk
            CHECK (status IN ('pending_approval','active','suspended','rejected'));
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'stores_rejection_reason_chk') THEN
        ALTER TABLE stores ADD CONSTRAINT stores_rejection_reason_chk
            CHECK (status <> 'rejected' OR (rejection_reason IS NOT NULL AND length(btrim(rejection_reason)) >= 10));
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'stores_tier_chk') THEN
        ALTER TABLE stores ADD CONSTRAINT stores_tier_chk
            CHECK (subscription_tier IN ('free','standard','pro'));
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'stores_approved_by_fk') THEN
        ALTER TABLE stores ADD CONSTRAINT stores_approved_by_fk
            FOREIGN KEY (approved_by) REFERENCES platform_super_admins(id) ON DELETE RESTRICT;
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS stores_status_idx ON stores (status);
