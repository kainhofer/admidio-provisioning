-- Child tables first: MySQL and MariaDB ignore the CASCADE keyword on DROP TABLE.
DROP TABLE IF EXISTS %PREFIX%_provisioning_status CASCADE;
DROP TABLE IF EXISTS %PREFIX%_provisioning_queue CASCADE;
DROP TABLE IF EXISTS %PREFIX%_provisioning_members CASCADE;
DROP TABLE IF EXISTS %PREFIX%_provisioning_accounts CASCADE;
DROP TABLE IF EXISTS %PREFIX%_provisioning_groups CASCADE;
DROP TABLE IF EXISTS %PREFIX%_provisioning_targets CASCADE;
