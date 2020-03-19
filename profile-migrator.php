<?php
/*
Plugin Name: Profile Migrator
Plugin URI: https://github.com/schrauger/profile-migrator
Description: One-shot plugin. Converts profiles from old UCF COM theme to the new Colleges-Theme style.
If you run into timeout issues, increase the php-fpm and nginx timeouts. Also, you can limit the posts per page,
then modify the offset and simply deactivate and reactivate the plugin to run the code for each set.
Version: 1.3.0
Author: Stephen Schrauger
Author URI: https://github.com/schrauger/profile-migrator
License: GPL2
*/

class profile_migrator {
	static function run_network_migration(){
		$all_sites = get_sites();

		foreach ($all_sites as $site){
			switch_to_blog($site->blog_id);
				self::convert();
			restore_current_blog();
		}

	}

	// Loops through all profile types
	static function convert(){
		self::alter_post_type();
		self::alter_post_taxonomy();
		self::alter_acf_references();
		self::alter_shortcode_references();
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
						 WHERE taxonomy LIKE %s and post_type LIKE %s", "{$old_taxonomy}", "{$new_taxonomy}", "%{$old_taxonomy}%", "%person%"));
		}
	}

	static function alter_acf_references(){
		$loop = new WP_Query(
			array(
				'post_type' => ['person','profiles'],
				'posts_per_page' => 1000,
				'offset' => 0000, // need to cycle between 0, 1000, 2000, and 3000.
				//'s' => 'Deborah German'
			)
		);
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
						'post' => ', '
					],
					[
						'acf' => 'first_name',
						'post' => ' '
					],
					'middle_initial'
				 ], 'person_orderby_name'
			);
			self::concatonate_old_acf_to_new_field(
				[
					[
						'acf' => 'avf_last_name_1',
						'post' => ', '
					],
					[
						'acf' => 'avf_first_name_1',
						'post' => ' '
					],
					'avf_middle_initial_1'
				 ], 'person_orderby_name'
			);
			self::biography_to_main();
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

	/**
	 * Find-replace on all posts and all post types, replacing old shortcode references with new ones
	 */
	static function alter_shortcode_references(){
		global $wpdb;
		$shortcode_old_new = array('accordion' => 'ucf_college_accordion',);
		foreach ($shortcode_old_new as $old_type => $new_type) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) 
                         WHERE post_content LIKE %s", "[{$old_type}]", "[{$new_type}]", "%[{$old_type}]%") ); // shortcodes end either with a ] or a space (with arguments)
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) 
                         WHERE post_content LIKE %s", "[{$old_type} ", "[{$new_type} ", "%[{$old_type} %") ); // shortcodes end either with a ] or a space (with arguments)
		}
	}
}

// run profile migration upon plugin activation
register_activation_hook(__FILE__, ['profile_migrator','run_network_migration']); // run once, when plugin is activated.
//add_action('init',['profile_migrator','run_network_migration']); // this runs the entire script on every page load. testing only.
