<?php

/*
 * Labels for Server Suite's entries in the server Activity tab (#19). Merged
 * into the panel's own activity.php through the translation loader, under
 * server.serversuite.* (the event names are server:serversuite.*).
 */
return [
    'server' => [
        'serversuite' => [
            'mod-installed' => 'Installed :mod (:source) with Server Suite',
            'mod-updated' => 'Updated :mod to :version',
            'mods-updated-all' => 'Updated all mods',
            'mod-removed' => 'Removed :mod',
            'mod-adopted' => 'Started tracking :file',
            'mod-forgotten' => 'Stopped tracking :mod',
            'version-switch' => 'Started a switch to Minecraft :target',
            'loader-switch' => 'Started a loader switch',
            'reset' => 'Reset the server (:mode)',
            'config-saved' => 'Edited :file',
            'properties-saved' => 'Changed server.properties: :changed',
            'world-reset' => 'Reset the world for a new seed',
            'backup-schedule-saved' => 'Changed the backup schedule',
            'backup-rotated' => 'Ran a backup rotation',
            'backup-created' => 'Took a backup',
            'backup-renamed' => 'Renamed a backup to :name',
            'backup-locked' => 'Locked a backup',
            'backup-unlocked' => 'Unlocked a backup',
            'backup-deleted' => 'Deleted a backup',
            'backup-restored' => 'Restored a backup',
            'backup-downloaded' => 'Downloaded a backup',
            'subdomain-created' => 'Claimed :fqdn',
            'subdomain-removed' => 'Released a subdomain',
            'subdomain-repointed' => 'Re-pointed :fqdn',
            'status-card-enabled' => 'Turned on the status card for :fqdn',
            'status-card-disabled' => 'Turned off the status card for :fqdn',
            'own-domain-saved' => 'Set up its own domain',
            'own-domain-removed' => 'Removed its own domain',
            'subdomain-limit-set' => 'Subdomain allowance changed from :from to :to',
            'backup-plan-set' => 'Backup plan changed',
        ],
    ],
];
