-- First content for an empty cluster database, after the migrations Job
-- (extension:setup) has created the tables. Based on build/seed.sql of
-- bmack/typo3-k8s-starter, which expects the root page from `typo3 setup`;
-- here the root page is created as well. Fixed uids + INSERT IGNORE: safe to run twice.
-- Root page uid 1 = rootPageId in config/sites/main/config.yaml.

SET @now := UNIX_TIMESTAMP();

INSERT IGNORE INTO pages (uid, pid, sorting, title, slug, doktype, is_siteroot, crdate, tstamp, perms_userid, perms_user, perms_group, perms_everybody)
VALUES
  (1, 0, 256, 'Home',    '/',        1, 1, @now, @now, 1, 31, 27, 0),
  (2, 1, 256, 'About',   '/about',   1, 0, @now, @now, 1, 31, 27, 0),
  (3, 1, 512, 'Contact', '/contact', 1, 0, @now, @now, 1, 31, 27, 0);

INSERT IGNORE INTO tt_content (uid, pid, sorting, CType, colPos, header, bodytext, crdate, tstamp)
VALUES
  (1, 1, 256, 'text', 0, 'Welcome to K8s Starter',
   '<p>This TYPO3 runs on a 3-node Kubernetes cluster: two frontend pods, one backend pod, MariaDB and Valkey. Edit the pages in the TYPO3 backend; the page layout lives in the site package under <code>Resources/Private/Pages/</code>.</p>',
   @now, @now),
  (2, 2, 256, 'text', 0, 'About K8s Starter',
   '<p>Tell visitors who is behind this site and what it is for.</p>', @now, @now),
  (3, 3, 256, 'text', 0, 'Get in touch',
   '<p>Add your address, a contact form or a mail link here.</p>', @now, @now);
