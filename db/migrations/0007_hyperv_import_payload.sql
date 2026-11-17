-- =======================================================================
-- 0007_hyperv_import_payload.sql
--
-- Keep an uploaded collector file in the database rather than on disk.
--
-- 0005 put the payload in a folder and kept only a path here. On a bind
-- mounted deployment that folder belongs to the host's user and PHP-FPM runs
-- as www-data, so the first upload failed with "mkdir(): Permission denied"
-- — and, worse, the warning was printed before the redirect header, which
-- broke the response as well.
--
-- That is fixable with a chown, but it is a condition every future
-- deployment has to meet, and there is nothing in a staged file that wants a
-- filesystem: it is a few megabytes, it is read once, and it is deleted the
-- moment it has been imported. raw_object already keeps collector payloads
-- in MEDIUMTEXT for exactly these reasons.
--
-- MEDIUMTEXT holds 16 MiB. A node with 160 machines produces about 2 MB, so
-- the repository refuses anything over 12 MiB with a message rather than
-- letting MySQL truncate it silently.
ALTER TABLE hv_import_file
    ADD COLUMN payload MEDIUMTEXT NOT NULL COMMENT 'The uploaded file, verbatim' AFTER sha256,
    MODIFY COLUMN file_path VARCHAR(512) DEFAULT NULL
           COMMENT 'Unused since 0007; the payload is in the column beside it';
