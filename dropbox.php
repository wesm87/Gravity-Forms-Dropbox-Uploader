<?php
/**
 * Plugin Name:  Gravity Forms Dropbox Uploader
 * Plugin URI:   http://www.blueliquiddesigns.com.au / http://www.industriousmouse.co.uk
 * Description:  Uploads a file to your Dropbox folder.
 * Author:       Blue Liquid Designs / Industrious Mouse
 * Author URI:   http://www.blueliquiddesigns.com.au / http://www.industriousmouse.co.uk
 * Contributors: usableweb (http://wordpress.org/support/profile/usableweb) / Industrious Mouse https://github.com/industrious-mouse
 * Version:      1.1.1
 * Text Domain:
 *
 * @package GF_Dropbox_Uploader
 *
 * ------------------------------------------------------------------------

 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
 */

/* Split admin and front-end functions */
add_action( 'init',  array( 'GFdropbox', 'init' ) );
add_action( 'admin_init',  array( 'GFdropbox', 'admin_init' ) );

define( 'GF_DROPBOX_SETTINGS_URL', admin_url( 'admin.php?page=gf_settings&addon=Dropbox' ) );

/**
 * Main plugin class.
 */
class GFdropbox {

	/**
	 * The dropbox API class instance.
	 *
	 * @var Dropbox_API
	 */
	static $dropbox;

	/**
	 * This method is used to call the front end Dropbox Uploader Setup.
	 */
	public static function init() {

		// Check if Gravity Forms is installed.
		if ( ! self::check_gf_install() ) {
			return;
		}

		add_filter( 'gform_entry_post_save', array( __CLASS__, 'set_post_content' ), 10, 2 );
	}

	/**
	 * Initializes admin-specific features.
	 */
	public static function admin_init() {

		/* check Gravity Forms is installed */
		if ( ! self::check_gf_install() ) {
			self::gfdropboxe_nag_ignore();
			add_action( 'admin_notices', array( __CLASS__, 'gf_not_installed' ) );
			return;
		}

		self::admin_css();

		/* configure the settings page*/
		self::settings_page();

		/* install our hook on the advanced upload field - may have to create a custom object... */
		self::admin_dropbox_uploader();

	}

	/**
	 * [admin_dropbox_uploader description]
	 */
	private static function admin_dropbox_uploader() {

		// Initiate checkbox on fileuploader on forms page.
		add_action( 'gform_field_standard_settings', array( __CLASS__, 'my_advanced_settings' ), 10, 2 );
		add_action( 'gform_editor_js', array( __CLASS__, 'editor_script' ) );
		add_filter( 'gform_tooltips', array( __CLASS__, 'add_dropbox_tooltips' ) );
	}

	/**
	 * Checks if Gravity Forms is installed.
	 *
	 * @return bool
	 */
	private function check_gf_install() {
		return class_exists( 'GFCommon' );
	}

	/**
	 * Displays a notice informing the user that Gravity Forms is not installed.
	 */
	public static function gf_not_installed() {

		// Get the current user ID.
		$user_id = $GLOBALS['current_user']->ID;

		// Check that the user hasn't already dismissed the message.
		if ( ! get_user_meta( $user_id, 'gfdropboxe_ignore_notice' ) ) {
			$message = __(
				'You need to install <a href="http://www.gravityforms.com/">Gravity Forms</a> to use the Gravity Forms Dropbox Uploader plugin. | <a href="%1$s">Hide Notice</a>',
				''
			);

			echo '<div id="message" class="error"><p>';
			echo wp_kses_post( sprintf( $message, '?gfdropboxe_ignore_notice=1' ) );
			echo '</p></div>';
		}
	}

	/**
	 * Checks to see if the user has dismissed the notice about Gravity Forms not
	 * bing installed. If so, a new user meta value is saved so we know not to
	 * show the notice again.
	 */
	public static function gfdropboxe_nag_ignore() {

		$user_id = $GLOBALS['current_user']->ID;

		/* If user clicks to ignore the notice, add that to their user meta */
		if ( isset( $_GET['gfdropboxe_ignore_notice'] ) ) {
			add_user_meta( $user_id, 'gfdropboxe_ignore_notice', 'true', true );
		}
	}

	/**
	 * Initializes the plugin settings page.
	 */
	private static function settings_page() {

		if ( 'gf_settings' === RGForms::get( 'page' ) || rgget( 'oauth_token' ) ) {

			// Do validation before page is loaded.
			if ( rgpost( 'gf_dropbox_update' ) ) {

				// Parse and store variables in options table.
				self::update_dropbox_settings();

			} else if ( rgpost( 'gf_dropbox_authenticate' ) || rgget( 'oauth_token' ) ) {

				// Authenticate account.
				self::authenticate_dropbox_account();

			} else if ( rgpost( 'gf_dropbox_deauthenticate' ) ) {

				// De-authenticate account.
				delete_option( 'gf_dropbox_access_token' );

			}

			if ( rgget( 'auth' ) ) {
				add_action( 'admin_notices', array( __CLASS__, 'dropbox_connection_success' ) );
			} else if ( rgget( 'auth_error' ) ) {
				add_action( 'admin_notices', array( __CLASS__, 'dropbox_connection_problem' ) );
			}

			/* Call settings page and */
			RGForms::add_settings_page( 'Dropbox', array( __CLASS__, 'dropbox_settings_page' ), '' );
		}
	}

	/**
	 * Returns the url of the plugin's root folder
	 *
	 * @return string
	 */
	protected function get_base_url() {
		return plugins_url( null, __FILE__ );
	}

	/**
	 * Updates the admin area settings.
	 */
	private static function update_dropbox_settings() {

		// Validate form.
		$app_key    = sanitize_text_field( $_POST['dropbox_key'] );
		$app_secret = sanitize_text_field( $_POST['dropbox_secret'] );
		$directory  = sanitize_text_field( $_POST['dropbox_directory'] );
		$remove     = (int) $_POST['dropbox_remove'];

		// Update options.
		update_option( 'gf_dropbox_key', $app_key );
		update_option( 'gf_dropbox_secret', $app_secret );
		update_option( 'gf_dropbox_directory', $directory );
		update_option( 'gf_dropbox_remove', $remove );
	}

	/**
	 * This method is used to generate the Dropbox settings page in the Gravity Forms Settings Tab
	 */
	public static function dropbox_settings_page() {

		// Get option values.
		$app_key       = get_option( 'gf_dropbox_key' );
		$app_secret    = get_option( 'gf_dropbox_secret' );
		$directory     = get_option( 'gf_dropbox_directory' );
		$remove        = (int) get_option( 'gf_dropbox_remove' );
		$authenticated = get_option( 'gf_dropbox_access_token' );
		?>

		<div id="gf-dropbox">

			<form action="" method="post">
				<h3>
					<?php esc_html_e( 'Dropbox Uploader Settings', 'gf-dropbox-uploader' ); ?>
				</h3>

				<p>
					Before you can use the Dropbox Uploader in your Gravity Form's you'll need to authorize your account. Follow the steps below to setup the GF Dropbox Uploader:
					<ol>
						<li>Login to Dropbox and go to <a target="_blank" href="https://www.dropbox.com/developers/apps">https://www.dropbox.com/developers/apps</a></li>
						<li>Create a new App and select the 'Core' application type. <strong>Leave the permission type set to App Folder.</strong>.</li>
						<li>Copy the App Key and App Secret into the fields below and hit submit.</li>
						<li>Below the main form you'll be asked to authorize your account. Click Authorize and follow the prompts.</li>
					</ol>
				</p>

				<p>
					<label for="gf_dropbox_username">Dropbox App Key:</label>
					<input class="input" id="gf_dropbox_username" type="text" name="dropbox_key" value="<?php echo esc_attr( $app_key ); ?>" />
					<?php gform_tooltip( 'gf_dropbox_app_key' ); ?>
				</p>

				<p>
					<label for="gf_dropbox_password">Dropbox App Secret:</label>
					<input class="input" id="gf_dropbox_password" name="dropbox_secret" type="text" value="<?php echo esc_attr( $app_secret ); ?>" />
					<?php gform_tooltip( 'gf_dropbox_app_secret' ); ?>
				</p>

				<p>
					<label for="gf_dropbox_dir">Dropbox Upload Directory:</label>
					<input placeholder="Subfolder/#login#/#uniqueid#/" class="input" id="gf_dropbox_dir" name="dropbox_directory" type="text" value="<?php echo esc_attr( $directory ); ?>" />
					<?php gform_tooltip( 'gf_dropbox_app_upload' ); ?>
				</p>

				<p>
					<label for="gf_dropbox_del">Remove file from server once uploaded to Dropbox?</label>
					<input type="checkbox" value="1" id="gf_dropbox_del" name="dropbox_remove" <?php checked( $remove, 1 ); ?> />
					<?php gform_tooltip( 'gf_dropbox_app_remove' ); ?>
				</p>

				<?php wp_nonce_field( 'update', 'gf_dropbox_update' ); ?>

				<input type="submit" name="submit" class="button" value="Update Settings" />
			</form>

			<?php if ( empty( $authenticated ) && ! empty( $app_key ) && ! empty( $app_secret ) ) : ?>

				<hr />

				<form action="" method="post">
					<h3>
						Authenticate your Dropbox Account
					</h3>

					<p>
						You'll need to do this before users can upload files to your dropbox account.
					</p>

					<?php wp_nonce_field( 'update', 'gf_dropbox_authenticate' ); ?>

					<input type="submit" name="submit" class="button" value="Authenticate Account" />
				</form>

			<?php elseif ( ! empty( $app_key ) && ! empty( $app_secret ) ) : ?>

				<hr />

				<form action="" method="post">
					<h3>
						Deauthorize Dropbox Account
					</h3>

					<p>
						<em>Deauthorizing your application will stop the plugin working and your App will no longer have access to your account.</em>
					</p>

					<p>
						You'll need to deauthorize and then reauthorize your account if you change Dropbox Apps (ie. use a new App Key and App Secret)
					</p>

					<?php wp_nonce_field( 'update', 'gf_dropbox_deauthenticate' ); ?>

					<input type="submit" name="submit" class="button" value="Deauthorize Account" />
				</form>

			<?php endif; ?>

		</div>

		<?php
	}

	/**
	 * [authenticate_dropbox_account description].
	 */
	private static function authenticate_dropbox_account() {
		$app_key    = get_option( 'gf_dropbox_key' );
		$app_secret = get_option( 'gf_dropbox_secret' );
		$temp_state = get_option( 'gf_dropbox_state' );

		/* include the dropbox uploader */
		include 'dropbox-api/autoload.php';

		/* authenticate */
		$oauth = new Dropbox_OAuth_Curl( $app_key, $app_secret );
		$state = ( rgpost( 'gf_dropbox_authenticate' ) ) ? '1' : $temp_state;

		switch ( $state ) {
			case 1 :
				$tokens     = $oauth->getRequestToken();
				$return_url = 'http://' .$_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

				/*setup for the return */
				update_option( 'gf_dropbox_temp_token', serialize( $tokens ) );
				update_option( 'gf_dropbox_state', 2 );
				wp_redirect( $oauth->getAuthorizeURL( $return_url ) );
				exit();
			break;
			case 2 :
				/* set up temp token so we can get access token */
				$oauth->setToken( unserialize( get_option( 'gf_dropbox_temp_token' ) ) );

				$tokens = $oauth->getAccessToken();
				$oauth->setToken( $tokens );

				update_option( 'gf_dropbox_access_token', serialize( $tokens ) );
				delete_option( 'gf_dropbox_state' );

				$dropbox = new Dropbox_API( $oauth );
				$result = $dropbox->getAccountInfo();

				$redirect_arg = ( isset( $result['uid'] ) ) ? 'auth' : 'auth_error';
				$redirect_url = add_query_arg( $redirect_arg, '1', GF_DROPBOX_SETTINGS_URL );

				wp_redirect( $redirect_url );
				exit();
			break;
		}
	}

	/**
	 * Displays an authentication error notice.
	 */
	public static function dropbox_connection_problem() {

		$message = __(
			'Dropbox Authentication process failed. Please try and
				authenticate again.',
			''
		);
		?>
			<div id="message" class="error">
				<p>
					<?php echo esc_html( $message ); ?>
				</p>
			</div>
		<?php
	}

	/**
	 * Displays an authentication success notice.
	 */
	public static function dropbox_connection_success() {

		$message = __(
			'You have successfully connected your Dropbox account to Gravity
				Forms. You can now configure GF Uploads to save to your Dropbox
				App folder (via the edit forms page).',
			''
		);
		?>
		<div id="message" class="updated">
			<p>
				<?php echo esc_html( $message ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * This method is used add a checkbox under the Uploader Settings Section of the Gravity Forms creation engine.
	 *
	 * @param int $position Settings position.
	 * @param int $form_id  Form ID.
	 */
	public static function my_advanced_settings( $position, $form_id ) {

		// Create settings on position 50 (right after Admin Label).
		if ( -1 === $position ) :
			?>
			<li class="file_extensions_setting field_setting">
				<strong><?php esc_html_e( 'Dropbox', 'gf-dropbox-uploader' ); ?></strong>
				<label for="field_dropbox_value">
					<input type="checkbox" id="field_dropbox_value" onclick="SetFieldProperty( 'dropboxField', this.checked );" />
					Upload file to your Dropbox account?
					<?php gform_tooltip( 'form_dropbox_value' ); ?>
				</label>

				<label for="field_dropbox_path">
					Override default path:
					<input type="text" id="field_dropbox_path" value="<?php echo esc_attr( get_option( 'gf_dropbox_directory' ) ); ?>" onkeyup="SetFieldProperty( 'dropboxFieldPath', this.value );" size="40" />
					<?php gform_tooltip( 'form_dropbox_new_path' ); ?>
					<p><small><?php esc_html_e( '', 'gf-dropbox-uploader' ); ?></small></p>
				</label>
			</li>
			<?php
		endif;
	}

	/**
	 * Enqueues the script that tells the GF creation engine whether the checkbox is checked.
	 */
	public static function editor_script() {
		?>
		<script type='text/javascript'>
			(function( $ ) {
				// Binding to the load field settings event to initialize the checkbox.
				$(document).bind( 'gform_load_field_settings', function( event, field, form ) {
					$( '#field_dropbox_value' ).attr( 'checked', true === field['dropboxField'] );
					if ( undefined !== field['dropboxFieldPath'] ) {
						$( '#field_dropbox_path' ).val( field['dropboxFieldPath'] );
					}
				});
			})( jQuery );
		</script>
		<?php
	}

	/**
	 * Sprinkle some custom CSS in the admin area
	 */
	public static function admin_css() {
		wp_enqueue_style( 'gf-dropbox-css', plugins_url( 'gf-dropbox.css' , __FILE__ ) );
	}

	/**
	 * Adds tooltips to the GF Uploader Dropbox Checkbox.
	 *
	 * @param  array $tooltips The current array of tooltips.
	 * @return array $tooltips The updated array of tooltips.
	 */
	public static function add_dropbox_tooltips( $tooltips ) {
		$tooltips['form_dropbox_value']    = '<h6>Dropbox</h6>Check this box if you want the uploaded file stored in your Dropbox';
		$tooltips['gf_dropbox_app_key']    = 'You need to create a new App on Dropbox to get an App Key. Follow the instructions above to setup GF Dropbox Uploader.';
		$tooltips['gf_dropbox_app_secret'] = 'You need to create a new App on Dropbox to get an App Secret. Follow the instructions above to setup GF Dropbox Uploader.';
		$tooltips['gf_dropbox_app_upload'] = '<h6>Upload Directory</h6><p>You can save files to a subdirectory. Eg: <strong>New App Folder/</strong> (don\'t forget the forwardslash on the end).</p><p>You can include the following macros in the path: #login#, #date#, #time# and #uniqueid#. Eg: <strong>Subfolder/#login#/#uniqueid#/</strong></p>';
		$tooltips['gf_dropbox_app_remove'] = 'All uploaded files are transfered to your server before being uploaded to Dropbox. If you want to remove these files off your server after uploading to Dropbox then check this box.';
		$tooltips['form_dropbox_new_path'] = '<h6>Override Default Upload Directory</h6><p>This path will be used for all files uploaded with using this upload box.</p><p>You can include the following macros in the path: #login#, #date#, #time# and #uniqueid#</p>';

		return $tooltips;
	}


	/**
	 * Allows you to add macros to the Dropbox directory path
	 * Marcos include: #login#, #date#, #time#, #uniqueid#
	 *
	 * @param string $path The directory path.
	 * @param int    $id   __INCOMPLETE_DOCBLOCK__.
	 *
	 * @return mixed|string|void
	 */
	public static function replace_macros( $path, $id ) {
		global $current_user;

		if ( empty( $path ) ) {
			return '';
		}

		/* replace login macro */
		$login = empty( $current_user->user_login ) ? 'anonymous' : $current_user->user_login;
		$path  = str_replace( '#login#', $login, $path );

		/* replace date macro */
		$path = str_replace( '#date#', date( 'Y-m-d' ), $path );

		/* replace time macro */
		$path = str_replace( '#time#', date( 'H-i-s' ), $path );

		/* replace uniqueid macro */
		$path = str_replace( '#uniqueid#', $id, $path );

		return $path;
	}

	/**
	 * This method does the grunt work and actually uploads the file to the users Dropbox Folder.
	 *
	 * @throws Dropbox_Exception __INCOMPLETE_DOCBLOCK__.
	 *
	 * @param [type] $lead __INCOMPLETE_DOCBLOCK__.
	 * @param [type] $form __INCOMPLETE_DOCBLOCK__.
	 * @return bool
	 */
	public static function set_post_content( $lead, $form ) {

		$dropbox_upload = array();

		foreach ( $form['fields'] as $fields ) {

			if ( 1 === $fields['dropboxField'] ) {
				$id = (int) $fields['id'];

				// Skip current field if it doesn't exist.
				if ( ! $id || empty( $lead[ $id ] ) ) {
					continue;
				}

				// Get the form field override path.
				$upload_path = (
					isset( $fields['dropboxFieldPath'] ) && $fields['dropboxFieldPath']
					? $fields['dropboxFieldPath']
					: get_option( 'gf_dropbox_directory' )
				);

				// Create an array of files, to cater for Multi-Upload Fields.
				$files = (
					$this->is_json( $lead[ $id ] )
					? json_decode( $lead[ $id ] )
					: (array) $lead[ $id ]
				);

				foreach ( $files as $file ) {

					// Get details about the upload path and replace non-english characters.
					$file_name = preg_replace( '/[^\00-\255]+/u', '', basename( $file ) );

					// Check that file still has name and hasn't been stripped otherwise assign one.
					if ( 4 === strlen( $file_name ) ) {
						$file_name = md5( $id ) . $file_name;
					}

					// Replace the macros.
					$override_path = self::replace_macros( $upload_path, $lead['id'] );

					$dropbox_upload[] = array(
						'field_id'   => $id,
						'dir'        => $override_path,
						'file_name'  => $file_name,
						'file_url'   => $file,
						'local_path' => str_replace( site_url( '/' ), ABSPATH, $file ),
					);
				}
			}
		}

		// Get the Required Keys, Tokens and WP Options.
		$app_key     = get_option( 'gf_dropbox_key' );
		$app_secret  = get_option( 'gf_dropbox_secret' );
		$remove_file = get_option( 'gf_dropbox_remove' );
		$tokens      = unserialize( get_option( 'gf_dropbox_access_token' ) );
		$error       = array();

		// If the application hasn't been correctly configured and verified we won't run this.
		if ( empty( $app_key ) || empty( $app_secret ) || 0 === count( $tokens ) ) {
			return false;
		}

		// Include the Dropbox uploader.
		include 'dropbox-api/autoload.php';

		// Authenticate.
		$oauth = new Dropbox_OAuth_Curl( $app_key, $app_secret );
		$oauth->setToken( $tokens );

		// Initialize Dropbox API.
		self::$dropbox = new Dropbox_API( $oauth, 'sandbox' );

		// Upload the files.
		foreach ( $dropbox_upload as $file ) {

			$full_path = $file['dir'] . $file['file_name'];
			$response  = self::$dropbox->putFile( $full_path, $file['local_path'] );

			if ( $response && '1' === $remove_file ) {

				// Get the Dropbox Share URL if we're removing the file.
				// Update the lead meta information with the dropbox URL.
				try {
					$dropbox_share = self::$dropbox->share( $full_path, 'dropbox' );

					if ( isset( $dropbox_share['url'] ) ) {

						$current_url = $lead[ $file['field_id'] ];

						// Multi-Uploads are JSON encoded, so handle them differently.
						// Single file uploads are just standard text, no JSON Encoding.
						if ( $this->is_json( $current_url ) ) {
							$url = str_replace(
								wp_json_encode( $file['file_url'] ),
								wp_json_encode( $dropbox_share['url'] ),
								$current_url
							);

							$lead[ $file['field_id'] ] = $url;
						} else {
							$url = $dropbox_share['url'];
						}

						// Update the database field.
						GFAPI::update_entry_field( $lead['id'], $file['field_id'], $url );

						// Remove from the file from the local server.
						unlink( $file['local_path'] );
					}
				} catch ( Exception $e ) {
					// Handle errors.
					if ( true === WP_DEBUG ) {
						$message = "Error (Code {$e->getCode()}) :: {$e->getMessage()}";
						trigger_error( wp_kses_data( $message ), E_USER_WARNING );
					}
				}
			} else {
				$error[] = 'Could not upload ' . pathinfo( $file, PATHINFO_BASENAME ) . ' file to Dropbox: ' . $file;
			}
		}

		// If there are any problems let the site owner know about it.
		if ( count( $error ) ) {
			$to      = bloginfo( 'admin_email' );
			$subject = 'Gravity Form Dropbox Uploader Error';
			$message = implode( "\n\r", $error );
			$headers = 'From: no-reply@' . site_url() . ' <no-reply@' . site_url() . '>';

			wp_mail( $to, $subject, $message, $headers );
		}

		return $lead;
	}

	/**
	 * Checks if a given string is valid JSON.
	 *
	 * @param string $string The string to check.
	 * @return bool
	 */
	protected function is_json( $string ) {
		json_decode( $string );
		return ( json_last_error() === JSON_ERROR_NONE );
	}
}
