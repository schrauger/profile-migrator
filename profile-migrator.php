<?php
/*
Plugin Name: Profile Migrator
Plugin URI: https://github.com/schrauger/profile-migrator
Description: One-shot plugin. Converts profiles from old UCF COM theme to the new Colleges-Theme style.
If you run into timeout issues, increase the php-fpm and nginx timeouts. Also, you can limit the posts per page,
then modify the offset and simply deactivate and reactivate the plugin to run the code for each set.
Version: 2.0.0
Author: Stephen Schrauger
Author URI: https://github.com/schrauger/profile-migrator
License: GPL2
*/

class profile_migrator {
	const offset_multiplier = 0; /* increase this by 1 each time you activate the plugin.
								    if the number to convert is set at 100, this will offset by 100, 200, 300, etc.
                                    keep increasing this until all profiles are converted.
                                 */
	const profiles_to_convert_at_once = 20; // if you get timeouts, reduce this number to ease the load.

    static function add_actions(){
	    register_activation_hook(__FILE__, ['profile_migrator','admin_notice_setup']); // run once, when plugin is activated.
	    add_action( 'admin_notices', ['profile_migrator','admin_notice_profile_migrator'] ); // print out a notice on successful activation, telling the admin which range has been converted
	    add_action( 'network_admin_notices', ['profile_migrator','admin_notice_profile_migrator'] ); // same notice, but for network-enabled plugin section
	    //add_action('init',['profile_migrator','run_network_migration']); // this runs the entire script on every page load. testing only.

	    add_action( 'network_admin_menu', ['profile_migrator','add_options_page'] ); // adds converter settings page
	    add_action( 'wp_ajax_profile_migrator_get_sites', ['profile_migrator','ajax_get_sites'] ); // ajax to get a list of all sites
	    add_action( 'wp_ajax_profile_migrator_site_count_profiles', ['profile_migrator','ajax_site_count_profiles'] ); // ajax to get a list of all sites
	    add_action( 'wp_ajax_profile_migrator_site_quick_convert', ['profile_migrator','ajax_site_quick_convert'] ); // changes post type, taxonomy, and shortcode references
	    add_action( 'wp_ajax_profile_migrator_site_ranged_convert', ['profile_migrator','ajax_site_ranged_convert'] ); // ajax to get a list of all sites


    }

    // @deprecated
	static function run_network_migration(){
		set_transient( 'admin-notice-profile-migrator', true, 600 );

		$all_sites = get_sites();

		foreach ($all_sites as $site){
			switch_to_blog($site->blog_id);
				self::convert();
			restore_current_blog();
		}
	}

	static function admin_notice_setup(){
		set_transient( 'admin-notice-profile-migrator', true, 600 );
	}

	/**
	 * Shows a message once, when the plugin is activated.
	 */
	static function admin_notice_profile_migrator(){
		/* Check transient, if available display notice */

		if( get_transient( 'admin-notice-profile-migrator' ) ){

			?>
			<div class="updated notice is-dismissible">
                <p>Plugin activated. To run the conversion, go to the <a href="/wp-admin/network/settings.php?page=profile-migrator-options">plugin settings page.</a></p>
			</div>
			<?php
			/* Delete transient, only display this notice once. */
			delete_transient( 'admin-notice-profile-migrator' );
		}
	}

	// Loops through all profile types
    // @deprecated
	static function convert(){

		// only need to run the sql queries once. assume that multiplier>0 means we're running the script again for the next wave of profiles
		if (self::offset_multiplier == 0) {
			self::alter_post_type();
			self::alter_post_taxonomy();
			self::alter_shortcode_references();
		}
		self::alter_acf_references();

	}



	/**
	 * Alters the database to change post types from 'profiles' to 'person' for all existing 'profiles' posts
	 */
	static function alter_post_type(){
		global $wpdb;
		$old_post_types = array('profiles' => 'person',);
		foreach ($old_post_types as $old_type => $new_type) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_type = REPLACE(post_type, %s, %s) 
                         WHERE post_type LIKE %s", $old_type, $new_type, $old_type ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET guid = REPLACE(guid, %s, %s) 
                         WHERE guid LIKE %s", "post_type={$old_type}", "post_type={$new_type}", "%post_type={$old_type}%" ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET guid = REPLACE(guid, %s, %s) 
                         WHERE guid LIKE %s", "/{$old_type}/", "/{$new_type}/", "%/{$old_type}/%" ) );
		}
	}

	/**
	 * Alters the database to change custom taxonomy from 'profiles_category' to plain old 'category'
	 */
	static function alter_post_taxonomy(){
		global $wpdb;
		$old_taxonomy = array('profiles_category' => 'people_group',);
		foreach ($old_taxonomy as $old_taxonomy => $new_taxonomy){
			$wpdb->query( $wpdb->prepare("UPDATE {$wpdb->term_taxonomy} SET taxonomy = REPLACE(taxonomy, %s, %s)
						 WHERE taxonomy LIKE %s", "{$old_taxonomy}", "{$new_taxonomy}", "%{$old_taxonomy}%"));
			//echo $wpdb->last_query;
		}
	}

	/**
	 * Find-replace on all posts and all post types, replacing old shortcode references with new ones
	 */
	static function alter_shortcode_references(){
		global $wpdb;
		$shortcode_old_new = array('accordion' => 'ucf_college_accordion_deprecated',);
		foreach ($shortcode_old_new as $old_type => $new_type) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) 
                         WHERE post_content LIKE %s", "[{$old_type}]", "[{$new_type}]", "%[{$old_type}]%") ); // shortcodes end either with a ] or a space (with arguments)
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) 
                         WHERE post_content LIKE %s", "[{$old_type} ", "[{$new_type} ", "%[{$old_type} %") ); // shortcodes end either with a ] or a space (with arguments)
		}
	}



	static function alter_acf_references($range_start = null){
		$wp_query_options = array(
			'post_type' => ['person','profiles'],
			'posts_per_page' => self::profiles_to_convert_at_once,
            'post_status' => 'any',
        );
	    if ($range_start){
	        $wp_query_options['offset'] = $range_start;
        } else {
		    $wp_query_options['offset'] = self::profiles_to_convert_at_once * self::offset_multiplier; // need to cycle between 0, 1000, 2000, and 3000.;
        }

		$loop = new WP_Query($wp_query_options);
//		echo 'p: ' . $loop->post_count . '; ';
		while ($loop->have_posts()) {
			$loop->the_post();
			self::alter_acf_reference('position','person_jobtitle');
			self::alter_acf_sub_reference('phone','person_phone_numbers','number', 1);
			//self::alter_acf_sub_reference('fax','person_phone_numbers','number', 2); // we don't care about fax numbers anymore. don't bring them over.
			self::alter_acf_reference('email','person_email');
			self::alter_acf_reference('office_address','person_room');
			self::alter_acf_reference('education','person_educationspecialties');
			self::concatonate_old_acf_to_new_field(
				[
					[
						'acf' => 'last_name',
						'post' => ','
					],
					[
						'acf' => 'first_name',
						'pre' => ' '
					]
				 ], 'person_orderby_name'
			);
			self::concatonate_old_acf_to_new_field(
				[
					[
						'acf' => 'avf_last_name_1',
						'post' => ','
					],
					[
						'acf' => 'avf_first_name_1',
					    'pre' => ' ',
					],
					[
						'acf' => 'avf_middle_initial_1',
						'pre' => ' '
					]
				], 'person_orderby_name'
			);
			self::concatonate_old_acf_to_new_field(
				[
					[
						'acf' => 'res_last_name',
						'post' => ','
					],
					[
						'acf' => 'res_first_name',
						'pre' => ' '
					]
				], 'person_orderby_name'
			);
			self::biography_to_main();
			self::resident_fields_to_main();
			self::image_to_featured();
		}
	}

	/**
	 * Takes an old acf field name, grabs the value, and stores it in a new field name.
	 * Will not overwrite a new field if it already has data.
	 * @param string $old_name
	 * @param string $new_field
	 */
	static function alter_acf_reference(string $old_field, string $new_field){
		if (class_exists('acf')) { // simple check to make sure acf is installed and running at this point

			$new_value = get_field( $new_field );

			if ( ! $new_value ) {
				$old_value = get_field( $old_field );
				if ($old_value) {
					// if new field is empty, and old field has something, then copy old value to the new field
					update_field( $new_field, $old_value );
				}
			} else {
				// do nothing. don't overwrite if new field already has data (already migrated?),
				// and don't bother setting an empty new field if there's no data in the old field.
			}
		}
	}

	/**
	 * @param string $old_field
	 * @param string $new_field_parent
	 * @param string $new_field
	 * @param int    $index (1-based)
	 */
	static function alter_acf_sub_reference(string $old_field, string $new_field_parent, string $new_field, int $index){
		if (class_exists('acf')) { // simple check to make sure acf is installed and running at this point
			global $post;

			$repeater_values = get_field($new_field_parent);
			$first_row = $repeater_values[$index - 1]; // acf indexes are 1 based, but php arrays are 0 based.
			$new_value = $first_row[$new_field];

			if ( ! $new_value ) {
				$old_value = get_field( $old_field );
				if ($old_value) {
					// if new field is empty, and old field has something, then copy old value to the new field
					$current_rows = count(get_field($new_field_parent));
					if ($index > $current_rows) {
						// apparently, it doesn't auto insert a new row. do it manually.
						$sanity = 0;
						while (($index > count(get_field($new_field_parent))) && ($index < 10) && $sanity < 10){
							// sanity check of 10; increase if you know there can be more than 10 rows of some data
							add_row($new_field_parent, [$new_field => null]); //insert empty data, which will be overwritten later
							$sanity++;
						}
					}
					update_sub_field( [ $new_field_parent, $index, $new_field ], $old_value );
					//echo 'new data: ' . get_sub_field([$new_field_parent, $index, $new_field]);
				}
			} else {
				// do nothing. don't overwrite if new field already has data (already migrated?),
				// and don't bother setting an empty new field if there's no data in the old field.
			}
		}
	}

	/**
	 * Takes an array of old acf field names, concatonates the values, and saves it to a single new field.
	 * $old_fields is an array with either simple values (the acf field name),
	 * or an inner array with optional 'pre' and 'post' string values, and a required 'acf' field name.
	 * Will not overwrite a new field if it already has data.
	 *
	 * @param array $old_fields
	 *              ['last','first','middle']
	 *              or
	 *              [
	 *                  ['acf'=>'last', 'post'=>','],
	 *                  ['acf'=>'first', 'pre'=> ' ', 'post'=>' '],
	 *                  'middle'
	 *              ]
	 * @param       $new_field
	 */
	static function concatonate_old_acf_to_new_field(Array $old_fields, $new_field){
		if (class_exists('acf')) { // simple check to make sure acf is installed and running at this point
			$concat_value = '';
			$new_value = get_field( $new_field );

			foreach ($old_fields as $field) {
				if (is_array($field)){
					if ($field['acf'] && get_field($field['acf'])){
						if ($field['pre']){
							$concat_value .= $field['pre'];
						}
						$concat_value .= get_field($field['acf']);
						if ($field['post']){
							$concat_value .= $field['post'];
						}
					}
				} else {
					$concat_value .= get_field($field);
				}
			}
			if ( ! $new_value && $concat_value ) {
				// if new field is empty, and old field has something, then copy old value to the new field
				update_field( $new_field, $concat_value );
			} else {
				// do nothing. don't overwrite if new field already has data (already migrated?),
				// and don't bother setting an empty new field if there's no data in the old field.
			}
		}
	}

	// converts the 'biography' field to be the main post content
	static function biography_to_main(){
		global $post;
		$old_value = get_field( 'biography' );

		if (empty($post->post_content)){
			 wp_update_post([
			 	'ID' => $post->ID,
			 	'post_content' => $old_value
			 ]);

		} else {
			// do nothing. don't overwrite if new field already has data (already migrated?),
			// and don't bother setting an empty new field if there's no data in the old field.
		}
	}

	// converts resident fields to be the main post content
	static function resident_fields_to_main(){
		global $post;
		$school = get_field( 'res_medical_school' );
        $interests = get_field('res_career_interest');
        $fun_fact = get_field('res_fun_fact');

		if (empty($post->post_content)){
			wp_update_post([
				               'ID' => $post->ID,
				               'post_content' => trim("
				               <div class='school'>{$school}</div>
				               <div class='interests'>{$interests}</div>
				               <div class='fun_fact'>{$fun_fact}</div>
				               ")
			               ]);

		} else {
			// do nothing. don't overwrite if new field already has data (already migrated?),
			// and don't bother setting an empty new field if there's no data in the old field.
		}
	}


	// converts the image in the acf field to the featured image
	static function image_to_featured(){
		global $post;

		if (!(has_post_thumbnail())){
			$old_value = get_field('photo');
			if ($old_value) {
				set_post_thumbnail( $post, $old_value );
			}
		}
	}

	static function add_options_page(){
		add_submenu_page(
		        'settings.php',
                'Profile Migrator',
                'Profile Migrator',
                'manage_options',
                'profile-migrator-options',
                array('profile_migrator','options_page_contents')
        );
	}
	static function options_page_contents(){
		?>
        <h1>
			Profile Migrator
        </h1>
        <p>Click run to begin migration. Depending on the number of profiles, this will take a long time.</p>
        <button class="convert">Execute</button>
        <div class="conversion-table">
            <table>
                <thead>
                <tr>
                    <th>Site ID</th>
                    <!--<th>Initial conversion</th>-->
                    <th>Count</th>
                    <th>Progress</th>
                </tr>
                </thead>
                <tbody>

                </tbody>
            </table>
        </div>
        <script type="text/javascript" >
            jQuery(document).ready(function($) {

                $('button.convert').click(convert_execute);

                function convert_execute(){
                    $('button.convert').prop('disabled', true);
                    $('button.convert').html("<span class='text'>Processing</span> <span class='progress-indicator' style='display: inline-block; width: 1em;'></span>");

                    // first, get multisites.
                    // loop through each multisite, running the quick conversions, then ranged conversions for the profiles.

                    // since 2.8 ajaxurl is always defined in the admin header and points to admin-ajax.php
                    $.ajax({
                        type: "POST",
                        url: ajaxurl,
                        data: {
                            'action': 'profile_migrator_get_sites'
                        },
                        success: convert_sites,
                        dataType: "json"
                    });
                }

                function convert_sites(response){
                    console.log(response);


                    $.each(response, function(index, site_id){

                        $('div.conversion-table tbody').append(`
                        <tr class='site-${site_id}'>
                            <td class='site-id'>${site_id}</td>
                            <!--<td class='initial-conversion'></td>-->
                            <td class='conversion-count'><span class='converted'></span>/<span class='total'></span></td>
                            <td class='fully-converted'><span class='percent'></span></td>
                        </tr>
                        `);
                        site_count_profiles(site_id);
                        site_quick_convert(site_id);
                        site_ranged_convert({
                            site_id: site_id,
                            range_start: 0,
                            completed_range: 0,
                            first_call: true
                        });

                    })
                }

                function site_count_profiles(site_id){
                    $.ajax({
                        type: "POST",
                        url: ajaxurl,
                        data: {
                            'action': 'profile_migrator_site_count_profiles',
                            'site_id': site_id
                        },
                        success: function(response){$(`tr.site-${site_id} td.conversion-count span.total`).html(response)},
                        dataType: "json",
                        async: false // I know this is deprecated. But I'm writing a one-off conversion, so I don't care.
                    });
                }

                function site_quick_convert(site_id){
                    $.ajax({
                        type: "POST",
                        url: ajaxurl,
                        data: {
                            'action': 'profile_migrator_site_quick_convert',
                            'site_id': site_id
                        },
                        //success: function(response){$(`tr.site-${site_id} td.initial-conversion`).html(response)}, // no need to show this data. it doesn't take long, and it's only useful for debugging.
                        dataType: "json"
                    });
                }

                /**
                 * Loop through all ranges for a site, until the server sets the 'complete' flag
                 * @param options
                 */
                function site_ranged_convert(options){
                    let site_row = `tr.site-${options['site_id']}`;
                    let completed_count = options['completed_range'];
                    if (options.complete){
                        $(`${site_row} td.conversion-count span.converted`).html(completed_count);
                        $(`${site_row} td.fully-converted`).html('Complete!');
                        check_if_all_done();
                    } else {
                        $(`${site_row} td.conversion-count span.converted`).html(completed_count);
                        if (!options.first_call){
                            progress_indicator();
                            let total = $(`${site_row} td.conversion-count span.total`).html();
                            $(`${site_row} td.fully-converted span.percent`).html((parseInt(completed_count) / parseInt(total) * 100).toFixed(2) + '%');
                        }
                        $.ajax({
                            type: "POST",
                            url: ajaxurl,
                            data: {
                                'action': 'profile_migrator_site_ranged_convert',
                                'site_id': options['site_id'],
                                'range_start': options['range_start']
                            },
                            success: site_ranged_convert, //recursive call - server should respond with site id and next range for itself
                            dataType: "json"
                        })
                    }
                }
                function progress_indicator(){
                    let current_indicator = $(`button.convert span.progress-indicator`).html();
                    let next_indicator;
                    switch (current_indicator) {
                        case "|":
                            next_indicator = "/";
                            break;
                        case "/":
                            next_indicator = "-";
                            break;
                        case "-":
                            next_indicator = "\\";
                            break;
                        case "\\":
                        default:
                            next_indicator = "|";
                    }
                    $(`button.convert span.progress-indicator`).html(next_indicator);
                }

                function check_if_all_done(){
                    let all_done = true;
                    $('div.conversion-table td.fully-converted').each(function(){
                        if ($(this).html() !== "Complete!"){
                            all_done = false;
                        }
                    });
                    if (all_done){
                        $('button.convert').html('Conversion fully completed!');
                    }
                }
            });
        </script>
		<?php
    }


	static function ajax_get_sites(){
		$all_sites = get_sites();
        $all_sites_id = [];

        foreach ($all_sites as $site){
			$all_sites_id[] = $site->blog_id;
		}
		echo json_encode($all_sites_id);
        wp_die();
	}

	static function ajax_site_count_profiles(){
	    $site_id = $_POST['site_id'];

		switch_to_blog($site_id);

		$old_posttype_count = array_sum((array) wp_count_posts('profiles'));
		$new_posttype_count = array_sum((array) wp_count_posts('person'));

		restore_current_blog();

		echo json_encode(max($old_posttype_count, $new_posttype_count));
	    wp_die();
    }

	/**
	 * Runs sql queries for specific site. These are very fast, basically independent of the number of profiles on the site.
	 */
	static function ajax_site_quick_convert(){
		$site_id = $_POST['site_id'];

		switch_to_blog($site_id);
		self::alter_post_type();
		self::alter_post_taxonomy();
		self::alter_shortcode_references();
		restore_current_blog();

		echo json_encode("Done");
        wp_die();
	}


	static function ajax_site_ranged_convert(){
		$site_id = $_POST['site_id'];
        $range_start = $_POST['range_start'];

		switch_to_blog($site_id);
		self::alter_acf_references($range_start);
		$existing_count = array_sum((array) wp_count_posts('person'));

		restore_current_blog();

		$completed_range = $range_start + self::profiles_to_convert_at_once;

		$return_array = [];
        if ($completed_range >= $existing_count){
            // we are done converting for this site.
            $return_array['completed_range'] = $existing_count;
            $return_array['complete'] = true;
            $return_array['site_id'] = $site_id;
        } else {
            // still have more profiles to complete. set up the next range start
            $return_array['completed_range'] = $completed_range; // completed is 1-based.
            $return_array['range_start'] = $completed_range; // start is 0-based, so no need to add one here. just use the previous completed range.
	        $return_array['site_id'] = $site_id;
        }

		echo json_encode($return_array);
		wp_die();
	}

	static function ranged_convert($site_id, $range_start){
		switch_to_blog($site_id);
		self::alter_acf_references($range_start);
		restore_current_blog();
	}



}

profile_migrator::add_actions();

