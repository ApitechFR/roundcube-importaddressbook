<?php

// class rcmail_action_contacts_import_custom extends rcmail_action_contacts_import
// {
//     public static function import_confirm($attrib)
//     {
//         // Do nothing to prevent the HTML result message from being displayed
//     }
// }

class importaddressbook extends rcube_plugin
{
    // Instance variables
    private $rcmail; // Roundcube instance
    private $plugin_path; // path to the plugin folder
    private $config; // configuration values

    // Static variables
    private static $lock_file;

    function init()
    {
        // Initialize plugin_path variable
        $this->plugin_path = INSTALL_PATH . $this->url('');

        // Initialize the lock file path
        $this->lock_file = $this->plugin_path . 'importaddressbook.lock';

        // Get Roundcube instance
        $this->rcmail = rcmail::get_instance();

        // trigger on a post-connect hook with addressbook access
        //
        // login_after => too soon ("Addressbook source ($id) not found!")
        // storage_connected => too soon ("Addressbook source ($id) not found!")
        // smtp_connect => too soon ("Addressbook source ($id) not found!")
        // ready => OK but runs multiple times per refesh/actions
        // startup => OK but runs multiple times per refesh/actions
        //
        $this->add_hook('ready', array($this, 'importaddressbook_on_hook'));
    }

    /**
     * Get the list of files to import
     *
     * @param array $file_extensions List of valid file extensions
     *
     * @return array List of files (paths)
     */
    private function getImportFiles($file_extensions)
    {
        $files_to_import = array();

        error_log('[importaddressbook] Searching for files to import inside directory ' . $this->plugin_path . ' ...');

        // Find files matching extension(s)
        foreach ($file_extensions as $extension)
        {
            $pattern = sprintf('%s/*.%s', $this->plugin_path, $extension);
            $files = glob($pattern);
            if ($files !== false)
            {
                $files_to_import = array_merge($files_to_import, $files);
            }
        }

        error_log('[importaddressbook] Found '. count($files_to_import) . ' files to import !');
        return $files_to_import;
    }

    /**
     * Get the list of users contained inside provided files
     *
     * @param array $file_paths Paths of the files to read
     *
     * @return array List of vcards (rcube_vcard)
     */
    private function getVCardsFromFiles($file_paths)
    {
        $vcards = array();
        error_log('[importaddressbook] Converting file content to vCards...');

        foreach ($file_paths as $path)
        {
            // Read file
            $csv_content = file_get_contents($path);

            // Instanciate a new CSV to vCard converter
            $converter = new rcube_csv2vcard;

            // Import contacts from CSV file
            $converter->import(
                $csv_content, // Content of the CSV file
                false, // Generate automatic field mapping
                false // Skip header line
            );

            // Export contacts to vCards, and merge
            $vcards = array_merge($vcards, $converter->export());
        }

        error_log('[importaddressbook] Made '. count($vcards) . ' vCards from the provided files !');
        return $vcards;
    }

    /**
     * Import vCards into Adressbook
     * Partial code of 'run' function, copied from 'rcmail_action_contacts_import' class
     *
     * @param string $target
     * @param bool $replace
     * @param bool $with_groups
     * @param array $vcards
     *
     * @return stdClass (insertions: int $success, int $failed)
     */
    private function rcmail_action_contacts_import_run_vcards($target, $replace, $with_groups, $vcards)
    {
        $insertions = new stdClass;
        $insertions->success = 0;
        $insertions->failed  = 0;

        //-----

        $CONTACTS =  $this->rcmail->get_address_book(
            $target, // $id     Address book identifier. It accepts also special values
            true // $writeable  True if the address book needs to be writeable
        );

        if (!$CONTACTS->groups) {
            $with_groups = false;
        }

        //-----

        if ($replace) {
            $CONTACTS->delete_all($CONTACTS->groups && $with_groups < 2);
        }

        if ($with_groups) {
            $import_groups = $CONTACTS->list_groups();
        }

        foreach ($vcards as $vcard) {
            $a_record = $vcard->get_assoc();

            // Generate contact's display name (must be before validation), the same we do in save.inc
            if (empty($a_record['name'])) {
                $a_record['name'] = rcube_addressbook::compose_display_name($a_record, true);
                // Reset it if equals to email address (from compose_display_name())
                if ($a_record['name'] == ($a_record['email'][0] ?? null)) {
                    $a_record['name'] = '';
                }
            }

            // skip invalid (incomplete) entries
            if (!$CONTACTS->validate($a_record, true)) {
                // self::$stats->invalid++;
                continue;
            }

            // We're using UTF8 internally
            $email = null;
            if (isset($vcard->email[0])) {
                $email = $vcard->email[0];
                $email = rcube_utils::idn_to_utf8($email);
            }

            if (!$replace) {
                $existing = null;
                // compare e-mail address
                if ($email) {
                    $existing = $CONTACTS->search('email', $email, 1, false);
                }
                // compare display name if email not found
                if ((!$existing || !$existing->count) && $vcard->displayname) {
                    $existing = $CONTACTS->search('name', $vcard->displayname, 1, false);
                }
                // if ($existing && $existing->count) {
                //     self::$stats->skipped++;
                //     self::$stats->skipped_names[] = $vcard->displayname ?: $email;
                //     continue;
                // }
            }

            $a_record['vcard'] = $vcard->export();

            $plugin   =  $this->rcmail->plugins->exec_hook('contact_create', ['record' => $a_record, 'source' => null]);
            $a_record = $plugin['record'];

            // insert record and send response
            if (empty($plugin['abort'])) {
                $success = $CONTACTS->insert($a_record);
            }
            else {
                $success = $plugin['result'];
            }

            if ($success) {
                // assign groups for this contact (if enabled)
                if ($with_groups && !empty($a_record['groups'])) {
                    foreach (explode(',', $a_record['groups'][0]) as $group_name) {
                        if ($group_id = rcmail_action_contacts_import::import_group_id($group_name, $CONTACTS, $with_groups == 1, $import_groups)) {
                            $CONTACTS->add_to_group($group_id, $success);
                        }
                    }
                }

                // self::$stats->inserted++;
                // self::$stats->names[] = $a_record['name'] ?: $email;
                // error_log('Added user' . json_encode($vcard));
                $insertions->success++;
            }
            else {
                // self::$stats->errors++;
                $insertions->failed++;
                error_log('[importaddressbook] Error while trying to add user' . json_encode($vcard));
            }
        }

        //-----

        return $insertions;
    }

    function importaddressbook_on_hook($args)
    {
        // Open the lock file
        $lock_handle = fopen($this->lock_file, 'w+');

        // Attempt to obtain an exclusive lock
        if (flock($lock_handle, LOCK_EX))
        {
            // Define which extensions are valid
            $file_extensions = array('csv');

            // Get all matching files
            $files = $this->getImportFiles($file_extensions);

            if(count($files) > 0)
            {
                // Extract vCards (users) from these files
                $vcards = $this->getVCardsFromFiles($files);
                
                // Import these vCards/users
                $result = $this->rcmail_action_contacts_import_run_vcards(
                    'global', //targetbook  // TODO add to a configuration file and load dynamically !
                    true, //replace         // TODO add to a configuration file and load dynamically !
                    true, // $with_groups   // TODO add to a configuration file and load dynamically !
                    $vcards
                );

                // Delete files
                foreach ($files as $path) { unlink($path); }

                error_log('[importaddressbook] Successfully imported ' . $result->success . ' users.');
                error_log('[importaddressbook] Failed to import ' . $result->failed . ' users.');
            }
            else
            {
                error_log('[importaddressbook] No valid file found, nothing to do.');
            }

            // Release the lock
            flock($lock_handle, LOCK_UN);

            // Close the lock file handle
            fclose($lock_handle);

            // return $args;
        }
        else
        {
            error_log('[importaddressbook] Import is already running !');
            return $args;
        }
    }
}