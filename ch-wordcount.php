<?php


/*
Plugin Name: CH Easy Word Count
Plugin URI: https://haensel.pro/easy-word-count/
Description: Displays the word count of posts and pages in the overview tables. Also adds a dashboard widget and lets you sort your posts and pages by word count.
Author: Christian Hänsel
Version: 2.3
Author URI: https://chaensel.de
Text Domain: ch-wordcount
License:     GPLv2

CH Easy Word Count is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or
any later version.

CH Easy Word Count is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with CH Easy Word Count.
*/

class ChWordcount {

	public $total_word_count = 0;
	public $displayType = null;
	public $chApiUrl = "https://wc.haensel.pro/index.php";
	public $getPostStati = array( 'publish', 'pending', 'draft', 'auto-draft', 'future', 'private', 'inherit', 'trash' );

	public function __construct() {

		add_action( 'load-edit.php', array( $this, 'loadBefore' ) );

		add_filter( 'manage_posts_columns', array( $this, 'add_wordcount_column' ) );
		add_filter( 'manage_edit-post_sortable_columns', array( $this, 'manage_sortable_columns' ) );


		add_filter( 'manage_pages_columns', array( $this, 'add_wordcount_column' ) );
		add_filter( 'manage_edit-page_sortable_columns', array( $this, 'manage_sortable_columns' ) );

		add_action( 'manage_posts_custom_column', array( $this, 'ch_columns_content' ), 10, 2 );
		add_action( 'manage_pages_custom_column', array( $this, 'ch_columns_content' ), 10, 2 );

		add_action( 'wp_dashboard_setup', array( $this, 'ch_custom_dashboard_widgets' ) );
		add_action( 'plugins_loaded', array( $this, 'ch_load_textdomain' ) );

		add_filter( 'request', array( $this, 'revised_column_orderby' ) );

		add_action( 'admin_menu', array( $this, 'ch_add_submenu_page' ) );

		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'init', array( $this, 'usage' ) );
		//$this->usage();

	}


	function register_settings() {
		add_option( 'ch_wordcount_share_stats', true );
		register_setting( 'ch_wordcount_options_group', 'ch_wordcount_share_stats', 'myplugin_callback' );

	}


	public function loadBefore() {


		$screen = get_current_screen();

		// Only edit post screen:
		if ( 'edit-post' === $screen->id ) {
			// Before:
			global $post;
			$args    = array(
				'posts_per_page' => - 1,
				'post_status'    => $this->getPostStati
			);
			$myposts = get_posts( $args );

			foreach ( $myposts as $post ) :
				$wc = (int) $this->getWordCountByString( $post->post_content );
				update_post_meta( $post->ID, 'wordcount', $wc );
			endforeach;
		}

		if ( 'edit-page' === $screen->id ) {
			// Before:
			global $post;
			$args    = array(
				'posts_per_page' => - 1,
				'post_status'    => $this->getPostStati
			);
			$myposts = get_pages( $args );
			foreach ( $myposts as $post ) :
				$wc = (int) $this->getWordCountByString( $post->post_content );
				update_post_meta( $post->ID, 'wordcount', $wc );
			endforeach;
		}


	}


	public function usage() {
		$callurl = $this->chApiUrl . "?d=" . get_home_url();

		$share_stats = false;
		if ( get_option( 'ch_wordcount_share_stats' ) ) {
			$share_stats = true;
		}

		if ( $share_stats ) {
			$posts_data = $this->getAllWordCountByType( "post" );
			$pages_data = $this->getAllWordCountByType( "page" );
			$posts_wc   = number_format( $posts_data->wordcount, 0, ",", "." );
			$pages_wc   = number_format( $pages_data->wordcount, 0, ",", "." );

			$posts_count = number_format( $posts_data->itemcount, 0, ",", "." );
			$pages_count = number_format( $pages_data->itemcount, 0, ",", "." );

			$callurl .= "&posts_count=" . $posts_count . "&pages_count=" . $pages_count . "&posts_wc=" . $posts_wc . "&pages_wc=" . $pages_wc;

		}

		$data = wp_remote_get( $callurl, array( 'timeout' => 120, 'httpversion' => '1.1' ) );
	}

	public function ch_load_textdomain() {
		load_plugin_textdomain( 'ch-wordcount', false, dirname( plugin_basename( __FILE__ ) ) . '/lang/' );
	}


	/**
	 * Adding the word count column
	 *
	 * @param $columns
	 *
	 * @return array
	 */
	public function add_wordcount_column( $columns ) {
		//return array_merge( $columns, array( 'wordcount' => __( 'Words', 'ch-wordcount' ) ) );

		$column_meta = array( 'wordcount' => __( 'Words' ) );
		$columns     = array_slice( $columns, 0, 6, true ) + $column_meta + array_slice( $columns, 6, null, true );

		return $columns;


	}

	/**
	 * Make custom columns sortable.
	 *
	 * @access public
	 *
	 * @param Array $columns The original columns
	 *
	 * @return Array $columns The filtered columns
	 */
	public function manage_sortable_columns( $columns ) {
		// Add our columns to $columns array
		$columns['wordcount'] = array( 'wordcount', 1 );

		return $columns;
	}

	function revised_column_orderby( $vars ) {
		if ( isset( $vars['orderby'] ) && 'wordcount' == $vars['orderby'] ) {
			$vars = array_merge( $vars, array(
				'orderby'  => 'meta_value_num',
				'meta_key' => 'wordcount',
				'type'     => 'NUMERIC'
			) );
		}

		return $vars;
	}

	/**
	 * @param $string
	 *
	 * @return int
	 */
	public function getWordCountByString( $string ) {

		$string     = preg_replace( "/\.,/", "", $string );
		$word_count = str_word_count( strip_tags( strip_shortcodes( $string ) ), 0, "" );

		return $word_count;
	}


	/**
	 * Display the word count in the table rows
	 *
	 * @param $column_name
	 * @param $post_ID
	 */
	public function ch_columns_content( $column_name, $post_ID ) {
		if ( $column_name == 'wordcount' ) {
			echo get_post_meta( $post_ID, 'wordcount', true );

		}
	}


	/**
	 * Getting the sum of all words by content type
	 *
	 * @param null $type
	 *
	 * @return stdClass
	 */
	public function getAllWordCountByType( $type = null ) {
		if ( is_null( $type ) ) {
			$type = "post";
		}

		$ret            = new stdClass();
		$ret->wordcount = 0;
		$ret->itemcount = 0;

		$wpb_all_query = new WP_Query( array( 'post_type' => $type, 'post_status' => array( 'publish', 'pending', 'draft' ), 'posts_per_page' => - 1 ) );

		if ( $wpb_all_query->have_posts() ) :

			while ( $wpb_all_query->have_posts() ) :
				$wpb_all_query->the_post();
				$content        = get_the_content();
				$ret->wordcount += str_word_count( strip_tags( $content ), 0, "" );
				$ret->itemcount ++;
			endwhile;
		endif;

		return $ret;
	}

	/********************
	 * DASHBOARD WIDGET
	 ********************/


	/**
	 * Add the dashboard widget
	 */
	public function ch_custom_dashboard_widgets() {
		global $wp_meta_boxes;

		wp_add_dashboard_widget( 'custom_help_widget', __( 'CH Easy Word Count', 'ch-wordcount' ), array( $this, 'ch_wordcount_dashboard_content' ) );
	}

	/**
	 * The dashboard widget content
	 */
	public function ch_wordcount_dashboard_content() {

		echo '<div style="margin-top:15px">';
		echo '
		<h3>Donations are welcome</h3>
		If you like this plugin, feel free to buy me a beer - or a nice evening out with my wifey &hearts;.<br>
		<br>
		<form action="https://www.paypal.com/cgi-bin/webscr" method="post" target="_blank">
		<input type="hidden" name="cmd" value="_s-xclick" />
		<input type="hidden" name="hosted_button_id" value="TZ3XFDQNG89GE" />
		<input type="image" src="https://www.paypalobjects.com/en_US/i/btn/btn_donate_LG.gif" border="0" name="submit" title="PayPal - The safer, easier way to pay online!" alt="Donate with PayPal button" />
		<img alt="" border="0" src="https://www.paypal.com/en_DE/i/scr/pixel.gif" width="1" height="1" />
		</form>
		<br><br>
		';
		echo ' <a href="/wp-admin/options-general.php?page=wordcount_options.php">Settings and more</a><br>';
		echo __( 'Support at', 'ch-wordcount' );
		echo ' <a href="https://haensel.pro/easy-word-count/" target="_blank">haensel.pro</a><br>';
		echo '<a href="https://wordpress.org/support/plugin/ch-easy-word-count/reviews/#new-post" target="_blank" title="Opens in a new window and only takes a minute">Please rate this plugin with 5 stars <span style="color:red; font-size:16px">♥♥♥♥♥</span></a>';
		echo '</div>';
	}


	/*
	 * ADMIN PAGE
	 *
	 */

	public function ch_add_submenu_page() {
		add_submenu_page( 'options-general.php', "WordCount", 'WordCount', 'manage_options', 'wordcount_options.php', array( $this, 'ch_admin_options' ) );
	}


	public function ch_admin_options() {
		$request = file_get_contents( 'http://wc.haensel.pro/myapi.php' );

		$urls = null;

		$urls = json_decode( $request );

		include( 'inc' . DIRECTORY_SEPARATOR . 'admin.php' );
	}

}


$ch = new ChWordcount();

