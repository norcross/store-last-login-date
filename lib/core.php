<?php
/**
 * Store Last Login Date - Core Module
 *
 * Contains our core functionality
 *
 * @package Store Last Login Date
 */

if ( ! class_exists( 'SLLD_Core' ) ) {

// Start up the engine
class SLLD_Core
{

	/**
	 * call the loader.
	 */
	public function init() {
		add_action( 'wp_loaded',                        array( $this, 'loader'                  )           );
	}

	/**
	 * the actual loader function. there are many like it, but this one is mine
	 *
	 * @return void
	 */
	public function loader() {
		add_filter( 'login_redirect',                   array( $this, 'store_login_timestamp'   ),  10, 3   );
		add_action( 'personal_options',                 array( $this, 'show_login_on_profile'   )           );
		add_filter( 'pre_user_query',                   array( $this, 'last_login_sort_vars'    )           );
		add_action( 'manage_users_custom_column',       array( $this, 'user_column_data'        ),  10, 3   );
		add_filter( 'manage_users_columns',             array( $this, 'user_column_setup'       )           );
		add_filter( 'manage_users_sortable_columns',	array( $this, 'make_user_sortable_cols' )           );
	}

	/**
	 * store the login timestamp for a user before proceeding
	 *
	 * @param  [type] $redirect_to [description]
	 * @param  [type] $request     [description]
	 * @param  [type] $user        [description]
	 * @return [type]              [description]
	 */
	public function store_login_timestamp( $redirect_to, $request, $user ) {

		// bail on invalid user
		if ( empty( $user ) || ! is_object( $user ) || is_wp_error( $user ) ) {
			return $redirect_to;
		}

		// set the login timestamp
		self::set_login_timestamp( $user->ID, current_time( 'timestamp' ) );

		// now continue as normal
		return $redirect_to;
	}

	/**
	 * show the last login time on a user on the profile page
	 *
	 * @param  [type] $user [description]
	 * @return [type]       [description]
	 */
	public function show_login_on_profile( $user ) {

		// bail on missing or invalid user
		if ( empty( $user ) || ! is_object( $user ) || empty( $user->ID ) ) {
			return;
		}

		// get the login time
		$login  = self::get_login_timestamp( $user->ID );

		// set the formatting type we want to use
		$type   = apply_filters( 'slld_profile_format_type', 'human', $user->ID, $login );

		// set up display
		$show   = ! empty( $login ) && absint( $login ) !== absint( 9999999999 ) ? self::format_login_stamp( $login, esc_attr( $type ), $user->ID ) : __( 'never', 'store-last-login-date' );

		// filter the display
		$show   = apply_filters( 'slld_profile_display', $show, $user->ID, $login );

		// echo out the box
		echo '<tr class="user-last-login-time">';
			echo '<th>' . __( 'Last Login', 'store-last-login-date' ) . '</th>';
			echo '<td><em>' . esc_attr( $show ) . '</em></td>';
		echo '</tr>';
	}

	/**
	 * do the last login sorting
	 *
	 * @param  [type] $query [description]
	 * @return [type]        [description]
	 */
	public function last_login_sort_vars( $query ) {

		// bail on non-admin or without the screen function
		if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		// get our screen
		$screen = get_current_screen();

		// bail on non events
		if ( ! is_object( $screen ) || ! empty( $screen->id ) && $screen->id !== 'users' ) {
			return $query;
		}

		// call the global DB class
		global $wpdb;

		// target the last login
		if ( ! empty( $_GET['orderby'] ) && $_GET['orderby'] == 'last-login' ) {
			// do the vars
			$query->query_vars['meta_key']  = '_slld_last_login';
			$query->query_vars['orderby']   = 'meta_value_num';

			// mod it. MOD IT REAL GOOD
			$query->query_from    .= " LEFT OUTER JOIN $wpdb->usermeta AS umeta ON ($wpdb->users.ID = umeta.user_id) ";
			$query->query_where   .= " AND umeta.meta_key = '_slld_last_login' ";
			$query->query_orderby  = "ORDER BY umeta.meta_value " . ( $query->query_vars['order'] == 'ASC' ? 'asc ' : 'desc ' );
		}

		// and return it
		return $query;
	}

	/**
	 * fetch and display the user column data
	 *
	 * @param  [type] $value       [description]
	 * @param  [type] $column_name [description]
	 * @param  [type] $user_id     [description]
	 * @return [type]              [description]
	 */
	public function user_column_data( $value, $column_name, $user_id ) {

		// show last login
		if ( 'last-login' == $column_name ) {

			// get the login time
			$login  = self::get_login_timestamp( $user_id );

			// set up the display
			$show   = ! empty( $login ) && absint( $login ) !== absint( 9999999999 ) ? self::format_login_stamp( $login, 'date', $user_id ) . '<br>' . self::format_login_stamp( $login, 'time', $user_id ) : '<em>' . __( 'never', 'store-last-login-date' ) . '</em>';

			// filter the display
			return apply_filters( 'slld_column_display', $show, $user_id, $login );
		}

		// return the column value
		return $value;
	}

	/**
	 * add the 'last login' time to the user columns
	 *
	 * @param  [type] $columns [description]
	 * @return [type]          [description]
	 */
	public function user_column_setup( $columns ) {

		// add our custom column
		$columns['last-login']  = __( 'Last Login', 'store-last-login-date' );

		// return the columns
		return $columns;
	}

	/**
	 * set the last login and cohort group to be sortable
	 *
	 * @param  [type] $columns [description]
	 *
	 * @return [type]          [description]
	 */
	public function make_user_sortable_cols( $columns ) {

		// add the column item
		$columns['last-login']  = 'last-login';

		// send it back
		return $columns;
	}

	/**
	 * get the login timestamp for a user
	 *
	 * @param integer $user_id   the user ID
	 *
	 * @return integer           the login timestamp
	 */
	public static function get_login_timestamp( $user_id = 0 ) {

		// get my time
		$stamp  = get_user_meta( $user_id, '_slld_last_login', true );

		// if empty, store the fallback
		if ( empty( $stamp ) ) {
			self::set_login_timestamp( $user_id );
		}

		// now return it
		return ! empty( $stamp ) ? floatval( $stamp ) : 9999999999;
	}

	/**
	 * set the login timestamp for a user
	 *
	 * @param integer $user_id   the user ID
	 * @param integer $stamp     the timestamp
	 */
	public static function set_login_timestamp( $user_id = 0, $stamp = 9999999999 ) {
		update_user_meta( $user_id, '_slld_last_login', floatval( $stamp ) );
	}

	/**
	 * take the login timestamp and return it formatted
	 *
	 * @param  integer $login    the login stored timestamp
	 * @param  string  $type     the type of format requested. will take 'human', 'date', and 'time'
	 * @param integer $user_id   the user ID. only used for optional filtering
	 *
	 * @return string            the requested date / time formatted
	 */
	public static function format_login_stamp( $login = 0, $type = 'human', $user_id = 0 ) {

		// if no type is provided, just return the login timestamp
		if ( empty( $type ) ) {
			return $login;
		}

		// do the switch
		switch ( $type ) {

			// human diff
			case 'human':

				return human_time_diff( floatval( $login ), current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'store-last-login-date' );
				break;

			// date
			case 'date':

				// get my date format with a fallback
				$format = get_option( 'date_format', 'F j, Y' );

				// pass it through a filter
				$format = apply_filters( 'slld_date_format', $format, $user_id );

				// return the formatted date
				return date( $format, floatval( $login ) );
				break;

			// time
			case 'time':

				// get my time format with a fallback
				$format = get_option( 'time_format', 'g:i a' );

				// pass it through a filter
				$format = apply_filters( 'slld_time_format', $format, $user_id );

				// return the formatted date
				return date( $format, floatval( $login ) );
				break;
		}
	}

// end class
}

// end exists check
}

// Instantiate our class
$SLLD_Core = new SLLD_Core();
$SLLD_Core->init();


