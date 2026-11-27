CREATE TABLE tx_k8shealth_messenger_worker (
	hostname varchar(255) DEFAULT '' NOT NULL,
	started_at int(11) unsigned DEFAULT '0' NOT NULL,
	last_seen int(11) unsigned DEFAULT '0' NOT NULL,
	stopped_at int(11) unsigned DEFAULT '0' NOT NULL,
	handled int(11) unsigned DEFAULT '0' NOT NULL,
	failed int(11) unsigned DEFAULT '0' NOT NULL,
	last_error varchar(1024) DEFAULT '' NOT NULL,

	PRIMARY KEY (hostname)
);
