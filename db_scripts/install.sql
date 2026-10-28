/*==============================================================*/
/* Provisioning plugin schema.                                  */
/*                                                              */
/* Written for MySQL and MariaDB and translated for PostgreSQL  */
/* by Database::preparePgSqlQuery(). Indexes are CREATE INDEX   */
/* statements of their own, because PostgreSQL has no inline    */
/* KEY clause.                                                  */
/*                                                              */
/* No semicolon may appear in a comment: the installer splits    */
/* the file on a semicolon at the end of a line.                 */
/*                                                              */
/* There are no foreign keys to users and roles: a mapping has  */
/* to survive the deletion of its user or role, otherwise the   */
/* remote account or group could never be removed.              */
/*==============================================================*/

CREATE TABLE %PREFIX%_provisioning_targets
(
    pvt_id                      integer unsigned NOT NULL AUTO_INCREMENT,
    pvt_uuid                    varchar(36)      NOT NULL,
    pvt_org_id                  integer unsigned NOT NULL,
    pvt_name                    varchar(255)     NOT NULL,
    pvt_enabled                 boolean          NOT NULL DEFAULT false,
    pvt_connector               varchar(50)      NOT NULL,
    pvt_config                  text             NULL,
    pvt_secrets                 text             NULL,
    pvt_sso_type                varchar(10)      NULL,
    pvt_sso_client_id           integer unsigned NULL,
    pvt_user_roles              text             NULL,
    pvt_account_name_source     varchar(110)     NULL,
    pvt_attribute_mapping       text             NULL,
    pvt_group_catchall          boolean          NOT NULL DEFAULT false,
    pvt_verify_interval         integer          NOT NULL DEFAULT 0,
    pvt_usr_id_create           integer unsigned,
    pvt_timestamp_create        timestamp        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    pvt_usr_id_change           integer unsigned,
    pvt_timestamp_change        timestamp        NULL DEFAULT NULL,
    PRIMARY KEY (pvt_id)
)
ENGINE = InnoDB
DEFAULT CHARSET = utf8mb4
COLLATE = utf8mb4_unicode_ci;

CREATE UNIQUE INDEX %PREFIX%_idx_pvt_uuid ON %PREFIX%_provisioning_targets (pvt_uuid);
CREATE INDEX %PREFIX%_idx_pvt_org ON %PREFIX%_provisioning_targets (pvt_org_id);

CREATE TABLE %PREFIX%_provisioning_groups
(
    pvg_id                      integer unsigned NOT NULL AUTO_INCREMENT,
    pvg_uuid                    varchar(36)      NOT NULL,
    pvg_pvt_id                  integer unsigned NOT NULL,
    pvg_source                  varchar(10)      NOT NULL DEFAULT 'manual',
    pvg_key                     varchar(255)     NOT NULL,
    pvg_name                    varchar(255)     NOT NULL,
    pvg_roles                   text             NULL,
    pvg_state                   varchar(10)      NOT NULL DEFAULT 'active',
    pvg_remote_id               varchar(255)     NULL,
    pvg_created                 boolean          NOT NULL DEFAULT false,
    pvg_hash                    char(64)         NULL,
    pvg_last_sync               timestamp        NULL DEFAULT NULL,
    pvg_last_error              text             NULL,
    pvg_usr_id_create           integer unsigned,
    pvg_timestamp_create        timestamp        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    pvg_usr_id_change           integer unsigned,
    pvg_timestamp_change        timestamp        NULL DEFAULT NULL,
    PRIMARY KEY (pvg_id)
)
ENGINE = InnoDB
DEFAULT CHARSET = utf8mb4
COLLATE = utf8mb4_unicode_ci;

CREATE UNIQUE INDEX %PREFIX%_idx_pvg_uuid ON %PREFIX%_provisioning_groups (pvg_uuid);
CREATE UNIQUE INDEX %PREFIX%_idx_pvg_target_key ON %PREFIX%_provisioning_groups (pvg_pvt_id, pvg_source, pvg_key);

CREATE TABLE %PREFIX%_provisioning_accounts
(
    pva_id                      integer unsigned NOT NULL AUTO_INCREMENT,
    pva_pvt_id                  integer unsigned NOT NULL,
    pva_usr_id                  integer unsigned NOT NULL,
    pva_usr_uuid                varchar(36)      NOT NULL,
    pva_remote_id               varchar(255)     NULL,
    pva_created                 boolean          NOT NULL DEFAULT false,
    pva_member_key              varchar(255)     NULL,
    pva_hash                    char(64)         NULL,
    pva_active                  boolean          NOT NULL DEFAULT false,
    pva_last_sync               timestamp        NULL DEFAULT NULL,
    pva_last_error              text             NULL,
    PRIMARY KEY (pva_id)
)
ENGINE = InnoDB
DEFAULT CHARSET = utf8mb4
COLLATE = utf8mb4_unicode_ci;

CREATE UNIQUE INDEX %PREFIX%_idx_pva_target_user ON %PREFIX%_provisioning_accounts (pva_pvt_id, pva_usr_uuid);
CREATE INDEX %PREFIX%_idx_pva_target_usr_id ON %PREFIX%_provisioning_accounts (pva_pvt_id, pva_usr_id);
CREATE INDEX %PREFIX%_idx_pva_target_remote ON %PREFIX%_provisioning_accounts (pva_pvt_id, pva_remote_id);

CREATE TABLE %PREFIX%_provisioning_members
(
    pvm_pvg_id                  integer unsigned NOT NULL,
    pvm_pva_id                  integer unsigned NOT NULL,
    pvm_preexisting             boolean          NOT NULL DEFAULT false,
    PRIMARY KEY (pvm_pvg_id, pvm_pva_id)
)
ENGINE = InnoDB
DEFAULT CHARSET = utf8mb4
COLLATE = utf8mb4_unicode_ci;

CREATE INDEX %PREFIX%_idx_pvm_account ON %PREFIX%_provisioning_members (pvm_pva_id);

CREATE TABLE %PREFIX%_provisioning_queue
(
    pvq_id                      integer unsigned NOT NULL AUTO_INCREMENT,
    pvq_pvt_id                  integer unsigned NOT NULL,
    pvq_type                    varchar(20)      NOT NULL,
    pvq_key                     varchar(255)     NOT NULL,
    pvq_state                   varchar(10)      NOT NULL DEFAULT 'pending',
    pvq_not_before              timestamp        NULL DEFAULT NULL,
    pvq_attempts                integer unsigned NOT NULL DEFAULT 0,
    pvq_lease_token             varchar(36)      NULL,
    pvq_lease_expires           timestamp        NULL DEFAULT NULL,
    pvq_last_error              text             NULL,
    pvq_timestamp_create        timestamp        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (pvq_id)
)
ENGINE = InnoDB
DEFAULT CHARSET = utf8mb4
COLLATE = utf8mb4_unicode_ci;

CREATE UNIQUE INDEX %PREFIX%_idx_pvq_item ON %PREFIX%_provisioning_queue (pvq_pvt_id, pvq_type, pvq_key);
CREATE INDEX %PREFIX%_idx_pvq_due ON %PREFIX%_provisioning_queue (pvq_pvt_id, pvq_state, pvq_not_before);

CREATE TABLE %PREFIX%_provisioning_status
(
    pvs_pvt_id                  integer unsigned NOT NULL,
    pvs_lease_token             varchar(36)      NULL,
    pvs_lease_expires           timestamp        NULL DEFAULT NULL,
    pvs_last_reconcile_date     date             NULL,
    pvs_last_verify             timestamp        NULL DEFAULT NULL,
    pvs_last_run                timestamp        NULL DEFAULT NULL,
    pvs_last_cli_run            timestamp        NULL DEFAULT NULL,
    pvs_state                   varchar(20)      NOT NULL DEFAULT 'ok',
    pvs_paused_until            timestamp        NULL DEFAULT NULL,
    pvs_message                 text             NULL,
    pvs_capabilities            text             NULL,
    pvs_skipped_users           integer unsigned NOT NULL DEFAULT 0,
    pvs_attention_notified      date             NULL,
    PRIMARY KEY (pvs_pvt_id)
)
ENGINE = InnoDB
DEFAULT CHARSET = utf8mb4
COLLATE = utf8mb4_unicode_ci;

ALTER TABLE %PREFIX%_provisioning_targets
    ADD CONSTRAINT %PREFIX%_fk_pvt_org FOREIGN KEY (pvt_org_id) REFERENCES %PREFIX%_organizations (org_id) ON DELETE CASCADE ON UPDATE RESTRICT,
    ADD CONSTRAINT %PREFIX%_fk_pvt_usr_create FOREIGN KEY (pvt_usr_id_create) REFERENCES %PREFIX%_users (usr_id) ON DELETE SET NULL ON UPDATE RESTRICT,
    ADD CONSTRAINT %PREFIX%_fk_pvt_usr_change FOREIGN KEY (pvt_usr_id_change) REFERENCES %PREFIX%_users (usr_id) ON DELETE SET NULL ON UPDATE RESTRICT;

ALTER TABLE %PREFIX%_provisioning_groups
    ADD CONSTRAINT %PREFIX%_fk_pvg_target FOREIGN KEY (pvg_pvt_id) REFERENCES %PREFIX%_provisioning_targets (pvt_id) ON DELETE CASCADE ON UPDATE RESTRICT,
    ADD CONSTRAINT %PREFIX%_fk_pvg_usr_create FOREIGN KEY (pvg_usr_id_create) REFERENCES %PREFIX%_users (usr_id) ON DELETE SET NULL ON UPDATE RESTRICT,
    ADD CONSTRAINT %PREFIX%_fk_pvg_usr_change FOREIGN KEY (pvg_usr_id_change) REFERENCES %PREFIX%_users (usr_id) ON DELETE SET NULL ON UPDATE RESTRICT;

ALTER TABLE %PREFIX%_provisioning_accounts
    ADD CONSTRAINT %PREFIX%_fk_pva_target FOREIGN KEY (pva_pvt_id) REFERENCES %PREFIX%_provisioning_targets (pvt_id) ON DELETE CASCADE ON UPDATE RESTRICT;

ALTER TABLE %PREFIX%_provisioning_members
    ADD CONSTRAINT %PREFIX%_fk_pvm_group FOREIGN KEY (pvm_pvg_id) REFERENCES %PREFIX%_provisioning_groups (pvg_id) ON DELETE CASCADE ON UPDATE RESTRICT,
    ADD CONSTRAINT %PREFIX%_fk_pvm_account FOREIGN KEY (pvm_pva_id) REFERENCES %PREFIX%_provisioning_accounts (pva_id) ON DELETE CASCADE ON UPDATE RESTRICT;

ALTER TABLE %PREFIX%_provisioning_queue
    ADD CONSTRAINT %PREFIX%_fk_pvq_target FOREIGN KEY (pvq_pvt_id) REFERENCES %PREFIX%_provisioning_targets (pvt_id) ON DELETE CASCADE ON UPDATE RESTRICT;

ALTER TABLE %PREFIX%_provisioning_status
    ADD CONSTRAINT %PREFIX%_fk_pvs_target FOREIGN KEY (pvs_pvt_id) REFERENCES %PREFIX%_provisioning_targets (pvt_id) ON DELETE CASCADE ON UPDATE RESTRICT;
