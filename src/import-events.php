<?php

    /*
    Plugin Name: Loop Demo Plugin
    Description: My demo project.
    Version: 1.0
    Author: Alex Georgiev
    License: GPL2
    */

    define( 'LOOP_RECIPIENT', 'logging@agentur-loop.com' );

    // Set up actions for the Events Import settings page.
    if ( is_admin() ) {
        add_action( 'admin_menu', 'loop_admin_menu' );
        add_action( 'admin_init', 'import_events_page' );
        add_action( 'admin_notices', 'import_json_show_admin_notices' );
        add_filter( 'mime_types', 'add_json_mime' );
    }

    // Register actions for REST API and CLI
    add_action( 'rest_api_init', 'view_active_events_rest' );
    add_action( 'cli_init', 'import_json_cli_register_commands' );

    // Create a shortcode that shows all active events.
    add_shortcode( 'view_events', 'display_events_shortcode' );

    /**
     * Adjust default mime types to accept uploading JSON-formatted files.
     * 
     * @param $wp_get_mime_type array
     * 
     * @return array
     */
    function add_json_mime( $wp_get_mime_types ) {
        $wp_get_mime_types['json'] = 'application/json';

        return $wp_get_mime_types;
    }

    /**
     * Assign new submenu item in Tools menu.
     *
     */
    function loop_admin_menu() {
        add_submenu_page(
            'tools.php',
            'Import JSON Events',
            'Import Events', 
            'import', 
            'import_json_events', 
            'import_json_events_options_page'
        );
    }

    /**
     * Create options page for importing events.
     * 
     */
    function import_events_page() {
        // Sanitize data on import
        $args = array(
            'type' => 'array', 
            'sanitize_callback' => 'sanitize_import_file',
            'default' => null,
        );

        register_setting( 'import_json', 'json_import_file', 'sanitize_import_file' );

        add_settings_section(
            'import_json_section',
            '',
            'import_json_callback',
            'import_json'
        );

        add_settings_field(
            'import_json_file_field',
            __( 'JSON File', 'wordpress' ),
            'import_json_file_field',
            'import_json',
            'import_json_section'
        );
    }

    /**
     * Create a file upload field for the options page.
     * 
     * @return string
     */
    function import_json_file_field() {
        ?>
        <input type='file' id='json_import_file' name='json_import_file'>
        <?php
    }
    
    /**
     * Default callback for the options page.
     * 
     */
    function import_json_callback() {

    }
    
    /**
     * Shows admin notices. Needed to trigger admin notices on the options page.
     * 
     */
    function import_json_show_admin_notices() {
        settings_errors();
    }

    /**
     * Renders the HTML form on the options page using the Settings API.
     * 
     * @return string
     */
    function import_json_events_options_page() {
        ?>
        <form id='json-data-import' action='options.php' method='post' enctype='multipart/form-data'>
    
            <h2>Import JSON Events</h2>
    
            <?php
            settings_fields( 'import_json' );
            do_settings_sections( 'import_json' );
            submit_button();
            ?>
    
        </form>
        <?php
    }

    /**
     * Santize functions
     */

    /**
     * Validate and santiize the uploaded import files.
     * 
     */
    function sanitize_import_file() {
        global $wp_filesystem;

        require_once ( ABSPATH . '/wp-admin/includes/file.php' );
        WP_Filesystem();

        // Check for upload permissions.
        if ( !current_user_can('upload_files') ) {
            wp_die( __( 'You do not have permission to upload files.' ) );
        }

        // Check if the file was uploaded.
        if( !empty( $_FILES["json_import_file"]["tmp_name"] ) )
        {
            $file = wp_handle_upload( $_FILES["json_import_file"], array( 'test_form' => false ) );

            // 
            if( isset($file['error'] ) ) {
                return add_settings_error(
                    'import_json',
                    esc_attr( 'json_import_file' ),
                    $file['error'],
                    'error'
                );
            }

            $file_info = wp_check_filetype( basename( $file['file'] ), array( 'json' => 'application/json' ) );

            // Perform a check for extension and mime type.
            if( $file_info['ext'] === 'json' && $file_info['type'] == 'application/json' ) {
                $file_data = $wp_filesystem->get_contents( $file['file'] );

                // Remove the uploaded file
                wp_delete_file( $file['file'] );

                // Make sure only valid JSON data is uploaded.
                if( ( $events = @json_decode( $file_data ) ) === null ) {
                    add_settings_error(
                        'import_json',
                        esc_attr( 'json_import_file' ),
                        'The uploaded JSON file is malformatted.',
                        'error'
                    );
                }

                return handle_imported_events( $events );
            } else {
                return add_settings_error(
                    'import_json',
                    esc_attr( 'json_import_file' ),
                    'The selected file is not a valid JSON file.',
                    'error'
                );
            }
        }

        return add_settings_error(
            'import_json',
            esc_attr( 'json_import_file' ),
            'Please select a file to upload.',
            'error'
        );
    }

    /**
     * Sanitize and return the tags field in JSON format
     * 
     * @param $tags array
     * 
     * @return string
     */
    function sanitize_tags_field( $tags ) {
        return json_encode( array_map( 'sanitize_text_field', $tags ) );
    }

    /**
     * Handles all JSON-fromatted data and imports it in the database.
     * 
     * @param $events array
     * 
     * @return string Returns the number of created/updated events.
     */
    function handle_imported_events( $events ) {
        if( $imported_events = import_event_data( $events ) ) {
            // Send an email with details about the import.
            email_import_status( $imported_events['created'], $imported_events['updated'] );

            return add_settings_error(
                'import_json',
                esc_attr( 'json_import_file' ),
                sprintf( '%d events created, %d updated.', $imported_events['created'], $imported_events['updated'] ),
                'success'
            );
        }

        // Import complete, but no changes were made
        return add_settings_error(
            'import_json',
            esc_attr( 'json_import_file' ),
            'No events were created or updated (data is the same).',
            'warning'
        );
    }

    /**
     * Create a new event post type when an event with the specific ID doesn't exist.
     * 
     * @param $data array
     * 
     * @return boolean
     */
    function create_event_data( $data ) {
        global $user_ID;

        $new_event = array(
            'post_title'    => sanitize_text_field( $data->title ),
            'post_content'  => '',
            'post_status'   => 'draft',
            'post_date'     => date('Y-m-d H:i:s'),
            'post_author'   => $user_ID,
            'post_type'     => 'event',
            'post_category' => array(0),
        );

        if( $post_id = wp_insert_post( $new_event ) ) {
            // Title is not a custom field, we don't need it to pass it to update_event_data().
            unset( $data->title );

            return update_event_data( $data, $post_id );
        }

        return false;
    }

    function import_event_data( $events ) {
        // Track the number or created/updated events.
        $created_events = $updated_events = 0;

        

        // Pass all events to the importer.
        foreach( $events as $event ) {
            $event_id = get_event_post_id( $event->id );

            if( false !== $event_id ) {
                // Update event if exists.
                if( update_event_data( $event, $event_id ) ) {
                    $updated_events++;
                }
            } else {
                // Create a new event.
                if( create_event_data( $event ) ) {
                    $created_events++;
                }
            }

        }

        // Notify if at least one event was modified.
        if( $created_events || $updated_events ) {
            return array(
                'created'   => $created_events,
                'updated'   => $updated_events
            ); 
        }

        return false;
    }

    /**
     * Update event data for imported events.
     * 
     * @param $data array
     * @param $post_id integer
     * 
     * @return boolean
     */
    function update_event_data( $data, $post_id ) {
        global $wpdb;

        $is_updated = false;

        if( isset( $data->title ) ) {
            // Santiize the post title before updating it.
            $data->title = sanitize_text_field( $data->title );

            $post = array(
                'ID'           => $post_id,
                'post_title'   => $data->title,
            );
           
            $sql = $wpdb->prepare( "SELECT {$wpdb->posts}.post_title
            FROM {$wpdb->posts}
            WHERE {$wpdb->posts}.ID = %d
            LIMIT 1;", (int) $post_id );

            $result = $wpdb->get_results( $sql, ARRAY_A );

            if( isset( $result[0]['post_title'] ) && 0 !== strcmp( $result[0]['post_title'],  $data->title ) && wp_update_post( $post ) ) {
                $is_updated = true;
            }

            // Unset title so we don't pass it to the custom fields
            unset( $data->title );
        }

        foreach( $data as $meta_key => $meta_value ) {
            // Sanitize functions for each custom field
            $sanitize_fields = array(
                'text'  => 'sanitize_text_field',
                'email' => 'sanitize_email',
                'tags'  => 'sanitize_tags_field',
            );

            $sanitized_meta = isset( $sanitize_fields[$meta_key] ) ? $sanitize_fields[$meta_key] : $sanitize_fields['text'];

            // Sanitize the meta values
            $meta_value = $sanitized_meta( $meta_value );

            // Make sure only needed changes are made
            if( 0 !== strcmp( rwmb_get_value( $meta_key, '', $post_id ), $meta_value ) ) {
                rwmb_set_meta( $post_id, $meta_key, $meta_value );

                // rwmb_set_meta doesn't return true/false on succesful update, so we trigger it here
                $is_updated = true;
            }
        }

        return $is_updated;
    }

    /**
     * Check if a specific Event ID is already imported.
     * 
     * @param $id integer
     * 
     * @return mixed The Post ID or false if it doesn't exist.
     */
    function get_event_post_id( $id ) {
        global $wpdb;

        // Check for only published or draft events.
        $sql = $wpdb->prepare( "SELECT {$wpdb->postmeta}.post_id
        FROM {$wpdb->postmeta}
        INNER JOIN {$wpdb->posts} ON {$wpdb->postmeta}.post_id = {$wpdb->posts}.ID
        WHERE {$wpdb->postmeta}.meta_key = 'id'
        AND {$wpdb->postmeta}.meta_value = '%d'
        AND ({$wpdb->posts}.post_status = 'publish' OR {$wpdb->posts}.post_status = 'draft')
        LIMIT 1;", (int) $id );

        $result = $wpdb->get_results( $sql, ARRAY_A );

        return !empty( $result ) ? $result[0]['post_id'] : false;
    }

    /**
     * Retrieve a list of all active events.
     * 
     * @return array
     */
    function get_active_events() {
        global $wpdb;

        $output = '';
        $events = $timestamps = array();
     
        $sql = "SELECT {$wpdb->postmeta}.*, {$wpdb->posts}.post_title FROM {$wpdb->postmeta}
        INNER JOIN {$wpdb->posts} ON {$wpdb->posts}.ID = {$wpdb->postmeta}.post_id
        WHERE {$wpdb->posts}.post_type = 'event'
        AND {$wpdb->posts}.post_status = 'publish'
        AND {$wpdb->postmeta}.meta_key IN ('id','about','organizer','timestamp','email','address','latitude','longitude','tags');";

        $meta =  $wpdb->get_results( $sql, ARRAY_A );

        // Fetch and assign each custom field to the $events array
        foreach( $meta as $id => $meta_value ) {
            if( !isset( $events[$meta[$id]['title']] ) ) {
                $events[$meta[$id]['post_id']]['title'] = $meta[$id]['post_title'];
            }

            $events[$meta[$id]['post_id']][$meta[$id]['meta_key']] = $meta[$id]['meta_value'];
        }

        // Sort events based on the closest date.
        // I used this method rather than SQL ordering because each timestamp is stored in `meta_value` field in the postmeta table and
        // a complex query would be needed to sort it there, so I decided to use this instead.
        usort( $events, function( $a, $b ) {
            return ( abs( time() - strtotime( $a['timestamp'] ) ) - ( abs( time() - strtotime( $b['timestamp'] ) ) ) );
        } );

        return $events;
    }

    /**
     * Shortcode for displaying all active events.
     * 
     * @return string
     */
    function display_events_shortcode() {
        $events = get_active_events();

        foreach( $events as $id => $custom_fields ) {
            $date = time2relative( $custom_fields['timestamp'] );
            $tags = implode(', ', json_decode( $custom_fields['tags'] ) );

            $output .= <<<OUTPUT
            <h2>{$custom_fields['title']}</h2>
            <p><strong>ID:</strong> {$custom_fields['id']}</p>
            <p><strong>About:</strong> {$custom_fields['about']}</p>
            <p><strong>Organizer:</strong> {$custom_fields['organizer']}</p>
            <p><strong>Email:</strong> {$custom_fields['email']}</p>
            <p><strong>Date:</strong> {$date} ({$custom_fields['timestamp']})</p>
            <p><strong>Lat/Long:</strong> {$custom_fields['latitude']}, {$custom_fields['longitude']}</p>
            <p><strong>Tags:</strong> {$tags}</p>
            OUTPUT;
        }

        return $output;
    }

    /**
     * Send an email to the Site admin when an user updates their profile.
     * 
     * @return boolean
     */
    function email_import_status( $created_events, $updated_events ) {
        $title = __( 'Events Import Complete' );
        $message = __( "Hello!\n\nEvents were successfully imported (%d events created, %d events updated).\n\nKind regards,\nAlex" );

        return wp_mail( LOOP_RECIPIENT, $title, sprintf( $message, $created_events, $updated_events ) );
    }

    /**
     * Register a route to view active events through the REST API.
     * 
     */
    function view_active_events_rest() {
        register_rest_route( 'events/v2' , '/view/', array(
                'methods' => 'GET',
                'callback' => 'view_active_events_json'
            )
        );
    }

    /**
     * Retrieve the active events in JSON format for the REST API.
     * 
     */
    function view_active_events_json() {
        $response = new WP_REST_Response( get_active_events() );
        $response->set_status(200);

        return $response;
    }

    /**
     * Import JSON Events
     * 
     */
    class Import_Events_Cli {

        /**
         * Import events from local files or URLs.
         *
         * ## EXAMPLES
         *
         *     wp events import https://alex.net.co/data.json
         *     wp events import --file=/path/to/file.json --skip-email
         *
         */
        public function import( $args, $assoc_args ) {
            global $wp_filesystem;

            require_once ( ABSPATH . '/wp-admin/includes/file.php' );
            WP_Filesystem();

            $defaults = array(
                'file'        => isset( $args[0] ) ? $args[0] : false,
                'skip-email'  => false,
            );

            $assoc_args = wp_parse_args( $assoc_args, $defaults );

            if( false === $assoc_args['file'] ) {
                return WP_CLI::error( 'Please select a valid JSON file.' );
            }

            if( filter_var( $assoc_args['file'], FILTER_VALIDATE_URL )  ) {
                $response = WP_CLI\Utils\http_request( 'GET', $assoc_args['file'] );

                if ( 20 != substr( $response->status_code, 0, 2 ) ) {
                    WP_CLI::error( 'Could not load data.' );
                }
                 // Make sure only valid JSON data is uploaded.
                 if( ( $events = @json_decode( $response->body ) ) === null ) {
                    return WP_CLI::error( 'The JSON file is malformatted.' );
                }
            } else {
                $file = WP_CLI\Utils\normalize_path( ABSPATH . $assoc_args['file'] );

                if( !file_exists( $file ) ) {
                    return WP_CLI::error( $file );
                }
    
                $file_info = wp_check_filetype( WP_CLI\Utils\basename( $file ), array( 'json' => 'application/json' ) );
    
                // Check for valid extension and mime type.
                if( $file_info['ext'] === 'json' && $file_info['type'] == 'application/json' ) {
                    $file_data = $wp_filesystem->get_contents( $file );
    
                    // Make sure only valid JSON data is uploaded.
                    if( ( $events = @json_decode( $file_data ) ) === null ) {
                        return WP_CLI::error( 'The uploaded JSON file is malformatted.' );
                    }
                } else {
                    return WP_CLI::error( 'Please select a valid JSON file.' );
                }
            }

            // Handle the imported data
            if ( $imported_events = import_event_data( $events ) ) {
                if( false === $assoc_args['skip-email'] ) {
                    email_import_status( $imported_events['created'], $imported_events['updated'] );
                }

                return WP_CLI::success( sprintf('%d events created, %d updated.', $imported_events['created'], $imported_events['updated'] ) );
            }

            return WP_CLI::warning( 'No events were created or updated (data is the same).' );
        }
    
    }
    
    /**
     * Register events CLI command
     *
     */
    function import_json_cli_register_commands() {
        WP_CLI::add_command( 'events', 'Import_Events_Cli' );
    }

    /**
     * Converts timestamp to relative time
     * 
     * @see https://stackoverflow.com/a/2690541
     */
    function time2relative( $ts )
    {
        if( !ctype_digit( $ts ) ) {
            $ts = strtotime( $ts );
        }
            
        $diff = time() - $ts;
        if( $diff == 0 )
            return __( 'now' );
        elseif( $diff > 0 )
        {
            $day_diff = floor( $diff / 86400 );
            if( $day_diff == 0 )
            {
                if( $diff < 60 ) return __( 'just now' );
                if( $diff < 120 ) return __( '1 minute ago' );
                if( $diff < 3600 ) return floor($diff / 60) . __( ' minutes ago' );
                if( $diff < 7200 ) return __( '1 hour ago' );
                if( $diff < 86400 ) return floor($diff / 3600) . __( ' hours ago' );
            }
            if( $day_diff == 1 ) return __( 'Yesterday' );
            if( $day_diff < 7 ) return $day_diff . __( ' days ago' );
            if( $day_diff < 31 ) return ceil( $day_diff / 7 ) . __( ' weeks ago' );
            if( $day_diff < 60 ) return __( 'last month' );
            return date( 'F Y', $ts );
        }
        else
        {
            $diff = abs( $diff );
            $day_diff = floor( $diff / 86400 );

            if ( 0 == $day_diff )
            {
                if( $diff < 120 ) return __( 'in a minute' );
                if( $diff < 3600 ) return __( 'in ' ) . floor( $diff / 60 ) . __( ' minutes' );
                if( $diff < 7200 ) return __( 'in an hour' );
                if( $diff < 86400 ) return __( 'in ' ) . floor( $diff / 3600 ) . __( ' hours' );
            }

            if ( $day_diff == 1 ) return __( 'Tomorrow' );
            if ( $day_diff < 4 ) return date('l', $ts);
            if ( $day_diff < 7 + ( 7 - date('w') ) ) return __( 'next week' );
            if ( ceil( $day_diff / 7 ) < 4 ) return __( 'in ' ) . ceil( $day_diff / 7 ) . __( ' weeks' );
            if( date( 'n', $ts ) == date( 'n' ) + 1 ) return __( 'next month' );

            return date( 'F Y', $ts );
        }
    }