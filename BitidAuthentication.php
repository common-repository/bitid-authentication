<?php
	namespace Puggan;

	/**
	 * Class BitidAuthentication
	 * @package Puggan
	 *
	 * @property table_names table_names
	 */
	class BitidAuthentication
	{
		public function __construct()
		{
			$this->table_names = new table_names();
			register_activation_hook(__FILE__, [$this, 'install']);

			add_action('init', [$this, 'session_start']);

			add_action('admin_menu', [$this, 'menu']);
			add_action('login_enqueue_scripts', [$this, 'login_script']);
			add_filter('login_message', [$this, 'login_header']);
			add_action('plugins_loaded', [$this, 'load_translation']);
			add_action('template_redirect', [$this, 'callback']);
			add_action('wp_ajax_nopriv_bitid', [$this, 'ajax']);
			add_action('wp_logout', [$this, 'logout']);

			if(get_site_option("bitid_plugin_version") !== BITID_AUTHENTICATION_PLUGIN_VERSION)
			{
				add_action('plugins_loaded', [$this, 'install']);
			}
		}

		/**
		 * List all bitid-addresses registered on a user
		 *
		 * @param int|true $user_id
		 *
		 * @return DbLink[]
		 */
		function users_addresses($user_id)
		{
			$query = "SELECT * FROM {$this->table_names->links}";

			if($user_id !== TRUE)
			{
				$query .= "WHERE user_id = %d";
				$query = $this->table_names->wpdb->prepare($query, (int) $user_id);
			}

			return $this->table_names->wpdb->get_results($query, OBJECT_K);
		}

		/**
		 * Install database tables
		 */
		public function install()
		{
			$create_table_nonce = <<<SQL_BLOCK
CREATE TABLE {$this->table_names->nonce} (
  nonce VARCHAR(32) NOT NULL,
  address VARCHAR(34) DEFAULT NULL,
  session_id VARCHAR(40) NOT NULL,
  user_id BIGINT(20) UNSIGNED NOT NULL,
  nonce_action VARCHAR(40) NOT NULL,
  birth TIMESTAMP NOT NULL,
  PRIMARY KEY (nonce),
  KEY (birth)
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8
COLLATE=utf8_bin
SQL_BLOCK;

			$create_table_links = <<<SQL_BLOCK
CREATE TABLE {$this->table_names->links} (
  user_id BIGINT(20) UNSIGNED NOT NULL,
  address VARCHAR(34) COLLATE utf8_bin DEFAULT NULL,
  birth TIMESTAMP NOT NULL,
  pulse TIMESTAMP NOT NULL,
  PRIMARY KEY (address),
  KEY (user_id),
  KEY (birth),
  FOREIGN KEY (user_id) REFERENCES {$this->table_names->users}(ID)
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8
COLLATE=utf8_bin
SQL_BLOCK;

			require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
			dbDelta($create_table_nonce);
			dbDelta($create_table_links);

			update_option("bitid_plugin_version", BITID_AUTHENTICATION_PLUGIN_VERSION);
		}

		/**
		 * Make sure we have a session
		 * @return string session_id
		 */
		public function session_start()
		{
			$session_id = session_id();
			if($session_id)
			{
				return $session_id;
			}

			session_start();
			$session_id = session_id();
			return $session_id;
		}

		/**
		 * Add user-page in admin menu
		 * @hook admin_menu
		 * @return void
		 */
		public function menu()
		{
			// TODO: add a page where admin can see all bitid-addresses
			//add_options_page('Bitid Options', 'Bitid', 'edit_users', 'bitid-authentication', [$this, 'admin_page']);
			add_users_page(
				_x('My Bitid', 'page_title', 'bitid-authentication'),
				_x('Bitid', 'menu_name', 'bitid-authentication'),
				'read',
				'my-bitid',
				[$this, 'user_page']
			);
		}

		/**
		 * Create user page for admin addresses
		 * @hook add_users_page
		 * @return void
		 */
		public function user_page()
		{
			$user_id = get_current_user_id();
			if(!$user_id)
			{
				return;
			}

			$url = htmlentities("?page={$_REQUEST['page']}");
			$addresses = $this->users_addresses($user_id);

			$action = NULL;
			if(isset($_REQUEST['action2']) AND $_REQUEST['action2'] != '' AND $_REQUEST['action2'] != -1)
			{
				$action = $_REQUEST['action2'];
			}
			if(isset($_REQUEST['action']) AND $_REQUEST['action'] != '' AND $_REQUEST['action'] != -1)
			{
				$action = $_REQUEST['action'];
			}

			if($action)
			{
				switch($action)
				{
					case 'add':
						if(isset($_POST['address']))
						{
							$address = $_POST['address'];
							$default_address = $address;
							$bitid = new \BitID();
							if($bitid->isAddressValid($address, FALSE) OR $bitid->isAddressValid($address, TRUE))
							{
								$userlink_row = new DbLink();
								$userlink_row->user_id = $user_id;
								$userlink_row->address = $address;
								$userlink_row->birth = current_time('mysql');

								/** @noinspection PhpParamsInspection */
								$db_result = $this->table_names->wpdb->insert($this->table_names->links, $userlink_row);

								if($db_result)
								{
									echo self::notice(sprintf(__("The address '%s' is now linked to your account.", 'bitid-authentication'), $address));

									$addresses = $this->users_addresses($user_id);
								}
								else
								{
									echo self::notice(sprintf(__("Failed to link address '%s' to your account.", 'bitid-authentication'), $address), 'error');
								}
							}
							else
							{
								echo self::notice(sprintf(__("The address '%s' isn't valid.", 'bitid-authentication'), $address), 'error');
							}
						}
						else
						{
							$default_address = (string) @$_REQUEST['address'];
						}

						$legend_title = htmlentities(_x("Add bitid-address", 'legend_title', 'bitid-authentication'));
						$label_title = htmlentities(_x("Bitid-address", 'input_label', 'bitid-authentication'));
						$button_title = htmlentities(_x("Link to my account", 'button', 'bitid-authentication'));

						$qr_url = $this->callback_url(NULL, 'add');
						$url_encoded_url = urlencode($qr_url);

						$alt_text = htmlentities(_x("QR-code for BitID", 'qr_alt_text', 'bitid-authentication'), ENT_QUOTES);

						echo <<<HTML_BLOCK
<form action='{$url}&action=add' method='post'>
	<fieldset style='border: solid black 2px; width: 40em; padding: 10px; margin: 10px;'>
		<legend style='font-size: larger;'>
			{$legend_title}
		</legend>
		<div class='fieldset_content'>
			<label>
				<span style='width: 10em; display: inline-block;'>
					{$label_title}:
				</span>
				<input type='text' name='address' value='{$default_address}' style='width: 25em;'/>
			</label>
			<br />
			<input type='submit' value='{$button_title}' style='margin-left: 10em;' />
		</div>
	</fieldset>
	<p>
		<a href='{$qr_url}'>
			<img src='https://chart.googleapis.com/chart?cht=qr&chs=300x300&chl={$url_encoded_url}' alt='{$alt_text}' title='{$alt_text}' />
		</a>
	</p>
</form>
HTML_BLOCK;
						break;

					case 'delete':
						/** @var string[] $found_addresses Addresses we want to delete */
						$found_addresses = array();
						/** @var string[] $try_addresses Addresses we can to delete */
						$try_addresses = [];
						/** @var string[] $deleted_addresses Addresses we did delete */
						$deleted_addresses = array();
						/** @var string[] $failed_addresses Addresses we failed to delete */
						$failed_addresses = array();

						// did we use a link or the form? to mark addresses to delete
						if(isset($_REQUEST['bitid_row']))
						{
							foreach($_REQUEST['bitid_row'] as $address)
							{
								$found_addresses[$address] = $address;
							}
						}
						else if(isset($_REQUEST['address']))
						{
							$address = $_REQUEST['address'];
							$found_addresses[$address] = $address;
						}

						// No addresses to delete
						if(!$found_addresses)
						{
							if($_POST)
							{
								echo self::notice(__("Select some rows before asking to delete them", 'bitid-authentication'), 'error');
								break;
							}

							echo self::notice(sprintf(__("Missing paramater '%s'", 'bitid-authentication'), 'address'), 'error');
							break;
						}

						// Check if the addresses exists, then move them to $try_addresses
						foreach($addresses as $current_address)
						{
							if(isset($found_addresses[$current_address->address]))
							{
								$try_addresses[$current_address->address] = $current_address->address;
								unset($found_addresses[$current_address->address]);

								if(!$found_addresses)
								{
									break;
								}
							}
						}

						// Trying to remove an address that isn't ours
						if($found_addresses)
						{
							echo self::notice(
								sprintf(
									_n(
										"The address %s isn't connected to your account.",
										"Those addresses %s arn't connected to your account.",
										count($found_addresses),
										'bitid-authentication'
									),
									"'" . implode("', '", $found_addresses) . "'"
								),
								'error'
							);
						}

						// No addresses that we can delete, abort
						if(!$try_addresses)
						{
							break;
						}

						foreach($try_addresses as $address)
						{
							$db_result = $this->table_names->wpdb->delete($this->table_names->links, ['address' => $address, 'user_id' => $user_id]);

							if($db_result)
							{
								$deleted_addresses[$address] = $address;
							}
							else
							{
								$failed_addresses[$address] = $address;
							}
						}

						if($failed_addresses)
						{
							echo self::notice(
								sprintf(
									_n(
										"Failed to remove the address %s.",
										"Failed to remove those addresses %s.",
										\count($failed_addresses),
										'bitid-authentication'
									),
									"'" . implode("', '", $failed_addresses) . "'"
								),
								'error'
							);
						}

						if($deleted_addresses)
						{
							echo self::notice(
								sprintf(
									_n(
										"The address %s is no longer linked to your account.",
										"Those addresses %s is no longer linked to your account.",
										\count($deleted_addresses),
										'bitid-authentication'
									),
									"'" . implode("', '", $deleted_addresses) . "'"
								),
								'error'
							);

							$addresses = $this->users_addresses($user_id);
						}

						break;

					default:
						echo self::notice("Unknowed action: " . $_REQUEST['action'], 'error');
						break;
				}
			}

			$page_title = htmlentities(_x("My bitid-addresses", "page_title", 'bitid-authentication'));
			$add_link_title = htmlentities(__("Add New"));

			echo <<<HTML_BLOCK
<div class="wrap">
	<h2>
		<span>{$page_title}</span>
		<a class="add-new-h2" href="{$url}&action=add">{$add_link_title}</a>
	</h2>

HTML_BLOCK;

			if(!$addresses)
			{
				echo self::notice(__("You have no bitid-addresses connected to your account.", 'bitid-authentication'));
				return;
			}

			echo <<<HTML_BLOCK
	<form action='{$url}' method='post'>

HTML_BLOCK;
			$my_bitid_addresses = new MyBitidAddresses();
			$my_bitid_addresses->load_addresses($addresses);
			$my_bitid_addresses->display();

			echo <<<HTML_BLOCK
	</form>
</div>

HTML_BLOCK;
		}

		/**
		 * Generate an URL for bitid-callback
		 *
		 * @param string|false $nonce
		 * @param string|false $nonce_action
		 *
		 * @return false|string
		 */
		function callback_url($nonce = FALSE, $nonce_action = FALSE)
		{
			if(!$nonce AND $nonce_action)
			{
				$nonce = $this->nonce($nonce_action);
			}

			if(!$nonce)
			{
				return FALSE;
			}

			$url = home_url("bitid/callback?x=" . $nonce);

			if(substr($url, 0, 8) !== 'https://')
			{
				return 'bitid://' . substr($url, 7) . "&u=1";
			}

			return 'bitid://' . substr($url, 8);
		}

		/**
		 * Generate a nonce
		 *
		 * @param string $nonce_action
		 *
		 * @return false|string
		 */
		public function nonce($nonce_action)
		{
			$session_id = $this->session_start();
			if(!$session_id)
			{
				return FALSE;
			}

			// Clean up old nonces
			$query = "DELETE FROM {$this->table_names->nonce} WHERE birth < NOW() - INTERVAL 3 HOUR";
			$this->table_names->wpdb->query($query);

			$query = $this->table_names->wpdb->prepare("SELECT * FROM {$this->table_names->nonce} WHERE nonce_action = %s AND session_id = %s", $nonce_action, $session_id);
			/** @var DbNonce $nonce_row */
			$nonce_row = $this->table_names->wpdb->get_row($query, OBJECT);

			if($nonce_row)
			{
				return $nonce_row->nonce;
			}

			$nonce_row = new DbNonce();
			$nonce_row->nonce = \BitID::generateNonce();
			$nonce_row->nonce_action = $nonce_action;
			$nonce_row->session_id = $session_id;
			$nonce_row->birth = current_time('mysql');

			$user_id = get_current_user_id();
			if($user_id)
			{
				$nonce_row->user_id = $user_id;
			}

			/** @noinspection PhpParamsInspection */
			$db_result = $this->table_names->wpdb->insert($this->table_names->nonce, $nonce_row);

			if($db_result)
			{
				return $nonce_row->nonce;
			}

			return $db_result;
		}

		/**
		 * Append the qr-code to the login-page messages
		 *
		 * @param string $messages
		 *
		 * @hook login_message
		 * @return string
		 */
		public function login_header($messages)
		{
			$url = $this->callback_url(NULL, 'login');

			if(!$url)
			{
				return $messages;
			}

			$title = htmlentities(_x("BitID login", 'qr_image_label', 'bitid-authentication'));
			$alt_text = htmlentities(_x("QR-code for BitID", 'qr_alt_text', 'bitid-authentication'), ENT_QUOTES);

			$url_encoded_url = urlencode($url);
			$qr_url = htmlentities("https://chart.googleapis.com/chart?cht=qr&chs=300x300&chl={$url_encoded_url}");
			$messages .= <<<HTML_BLOCK
<div id='bitid'>
	<p>
		<span>{$title}:</span>
		<a href='{$url}'>
			<img src="{$qr_url}" alt="{$alt_text}" title="{$alt_text}" />
		</a>
	</p>
</div>
HTML_BLOCK;

			return $messages;
		}

		/**
		 * Add ajax-script to login-page
		 * @hook login_enqueue_scripts
		 * @return void
		 */
		public function login_script()
		{
			$ajax_url = admin_url('admin-ajax.php?action=bitid');

			$js = <<<JS_BLOCK
var bitid_interval_resource;
bitid_interval_resource = setInterval(
	function()
	{
		var ajax = new XMLHttpRequest();
		ajax.open("GET", "{$ajax_url}", true);
		ajax.onreadystatechange =
			function ()
			{
				if(ajax.readyState !== 4 || ajax.status !== 200)
				{
					return;
				}
				
				if(ajax.responseText > '')
				{
					var json = JSON.parse(ajax.responseText);

					if(json.html > '')
					{
						document.getElementById('bitid').innerHTML = json.html;
					}

					if(json.stop > 0)
					{
						window.clearInterval(bitid_interval_resource);
					}

					if(json.reload > 0)
					{
						var redirect = document.getElementsByName("redirect_to");
						if(redirect && redirect[0].value > '')
						{
							window.location.href = redirect[0].value;
						}
						else
						{
							window.location.href = "wp-admin/";
						}
					}
				}
			};
		ajax.send();
	},
	3000
);
JS_BLOCK;

			wp_add_inline_script('bitid-ajax', $js);
		}

		/**
		 * Load translations
		 * @hook plugins_loaded
		 * @return void
		 */
		public function load_translation()
		{
			load_plugin_textdomain('bitid-authentication', FALSE, basename(__DIR__));
		}

		public function callback()
		{
			// Ignore if not the right url
			if(strpos($_SERVER['REQUEST_URI'], "/bitid/callback") === FALSE)
			{
				return;
			}

			$uri = NULL;
			$nonce = NULL;

			// Load indata, may bew json enocded
			$input = $_POST;
			$raw_post_data = file_get_contents('php://input');
			if($raw_post_data[0] === "{")
			{
				$input = json_decode($raw_post_data, TRUE);
			}

			// Fetch the data we need
			$post_data = new CallbackData();
			$loaded_data = 0;
			foreach(CallbackData::keys() as $key)
			{
				if(isset($input[$key]))
				{
					$post_data->$key = (string) $input[$key];
					$loaded_data++;
				}
				else
				{
					$post_data->$key = NULL;
				}
			}

			if(!$loaded_data)
			{
				\BitID::http_error(20, 'No data recived');
				die();
			}

			$nonce = \BitID::extractNonce($post_data->uri);

			if(!$nonce OR strlen($nonce) != 32)
			{
				\BitID::http_error(40, 'Bad nonce');
				die();
			}

			$uri = $this->callback_url($nonce);

			if($uri !== $post_data->uri)
			{
				\BitID::http_error(10, 'Bad URI', NULL, NULL, array('expected' => $uri, 'sent_uri' => $post_data->uri));
				die();
			}

			$table_name_userlink = "{$GLOBALS['wpdb']->prefix}bitid_userlink";
			$query = $this->table_names->wpdb->prepare("SELECT * FROM {$this->table_names->nonce} WHERE nonce = %s", $nonce);
			/** @var DbNonce $nonce_row */
			$nonce_row = $this->table_names->wpdb->get_row($query, OBJECT);

			if(!$nonce_row)
			{
				\BitID::http_error(41, 'Bad or expired nonce');
				die();
			}

			if($nonce_row AND $nonce_row->address AND $nonce_row->address !== $post_data->address)
			{
				\BitID::http_error(41, 'Bad or expired nonce');
				die();
			}

			$bitid = new \BitID();

			$signValid = $bitid->isMessageSignatureValidSafe($post_data->address, $post_data->signature, $post_data->uri, FALSE);

			if(!$signValid)
			{
				\BitID::http_error(30, 'Bad signature');
				die();
			}

			if(!$nonce_row->address)
			{
				$nonce_row->address = $post_data->address;

				switch($nonce_row->nonce_action)
				{
					case 'login':
						$db_result = $this->table_names->wpdb->update($this->table_names->nonce, ['address' => $nonce_row->address], ['nonce' => $nonce]);
						if($db_result)
						{
							break;
						}
						\BitID::http_error(50, 'Database failer', 500, 'Internal Server Error');
						die();

					case 'add':
						if($nonce_row->user_id)
						{
							$query = $this->table_names->wpdb->prepare(
								"INSERT INTO {$this->table_names->links} SET user_id = %d, address = %s, birth = NOW()",
								$nonce_row->user_id,
								$post_data->address
							);
							$this->table_names->wpdb->query($query);
							break;
						}
						\BitID::http_error(51, "Can't add bitid to a userless session", 500, 'Internal Server Error');
						die();

					default:
						\BitID::http_error(52, 'Unknown nonce-action', 500, 'Internal Server Error');
						die();

				}
			}

			\BitID::http_ok($post_data->address, $nonce);
			die();
		}

		public function ajax()
		{
			$session_id = $this->session_start();

			$query = $this->table_names->wpdb->prepare("SELECT * FROM {$this->table_names->nonce} WHERE session_id = %s", $session_id);
			/** @var DbNonce $nonce_row */
			$nonce_row = $this->table_names->wpdb->get_row($query, OBJECT);

			if(!$nonce_row)
			{
				$data = new AjaxResponse();
				$data->status = -1;
				$data->html = "<p>" . __("Error: The current session doesn't have a bitid-nonce.", 'bitid-authentication') . "</p>";
				die(json_encode($data, JSON_PRETTY_PRINT) . PHP_EOL);
			}

			switch($nonce_row->nonce_action)
			{
				case 'login':
					$data = new AjaxResponse();
					if(!$nonce_row->address)
					{
						$data->status = 0;
						die($data);
					}

					$data->status = 1;
					$data->address = $nonce_row->address;

					$query = $this->table_names->wpdb->prepare("SELECT * FROM {$this->table_names->links} WHERE address = %s", $data->address);
					/** @var DbLink $user_row */
					$user_row = $this->table_names->wpdb->get_row($query, OBJECT);
					if(!$user_row)
					{
						$data->html = "<p>" . sprintf(__("Bitid verification Sucess, but no useraccount connected to '%s'", 'bitid-authentication'), $data->address) . "</p>";
						die($data);
					}

					$this->table_names->wpdb->delete($this->table_names->nonce, ['session_id' => $session_id]);

					if(is_user_logged_in())
					{
						$data->html = "<p>" . __("Allredy logged in", 'bitid-authentication') . "</p>";
						$data->reload = 1;
						die($data);
					}

					$user = get_user_by('id', $user_row->user_id);

					if(!$user)
					{
						$data->html = "<p>" . sprintf(__("Bitid verification Sucess, but no useraccount connected to '%s'", 'bitid-authentication'), $data->address) . "</p>";
						die($data);
					}

					wp_set_current_user($user->ID, $user->user_login);
					wp_set_auth_cookie($user->ID);
					do_action('wp_login', $user->user_login, $user);

					$data->html = "<p>" . sprintf(__("Sucess, loged in as '%s'", 'bitid-authentication'), $user->user_login) . "</p>";
					$data->reload = 1;

					$update_query = $this->table_names->wpdb->prepare("UPDATE {$this->table_names->links} SET pulse = NOW() WHERE address = %s", $data->address);
					$this->table_names->wpdb->query($update_query);

					die($data);
			}

			$data = new AjaxResponse();
			$data->status = -1;
			$data->html = "<p>" . __("Unknown action: ", 'bitid-authentication') . $nonce_row->nonce_action . "</p>";
			die($data);
		}

		function logout()
		{
			$session_id = $this->session_start();

			if(!$session_id)
			{
				return FALSE;
			}

			$query = $this->table_names->wpdb->prepare("SELECT * FROM {$this->table_names->nonce} WHERE session_id = %s", $session_id);

			if($this->table_names->wpdb->get_row($query, OBJECT))
			{
				$this->table_names->wpdb->delete($this->table_names->nonce, ['session_id' => $session_id]);
			}
		}

		/**
		 * Format a notice for admin-pages
		 *
		 * @param string $message
		 * @param string $class updated or error
		 *
		 * @return string
		 */
		public static function notice($text, $class = 'updated')
		{
			$text = htmlentities($text);
			$class = htmlentities($class);
			return <<<HTML_BLOCK
	<div class='{$class}'>
		<p>{$text}</p>
	</div>

HTML_BLOCK;
		}
	}

	/**
	 * Class phpdoc_table_names
	 * @package Puggan
	 * @property string nonce
	 * @property string links
	 * @property string users
	 * @property \wpdb wpdb
	 */
	class table_names
	{
		public function __construct()
		{
			$this->wpdb = $GLOBALS['wpdb'];
			$p = $this->wpdb->prefix;
			$this->nonce = $p . 'bitid_nonce';
			$this->links = $p . 'bitid_userlink';
			$this->users = $p . 'users';
		}
	}

	/**
	 * Class MyBitidAddresses
	 * @package Puggan
	 * @property DbLink[] $items
	 */
	class MyBitidAddresses extends \WP_List_Table
	{
		/**
		 * @return string[]
		 */
		public function get_columns()
		{
			return [
				'cb' => '<input type="checkbox" />',
				'address' => _x('Bitid-address', 'column_name', 'bitid-authentication'),
				'birth' => _x('Added', 'column_name', 'bitid-authentication'),
				'pulse' => _x('Last time used', 'column_name', 'bitid-authentication'),
			];
		}

		/**
		 * @return string[][]|false[][]
		 */
		public function get_sortable_columns()
		{
			return [
				'address' => [
					'address',
					FALSE,
				],
				'birth' => [
					'birth',
					FALSE,
				],
				'pulse' => [
					'pulse',
					FALSE,
				]
			];
		}

		/**
		 * @param DbLink[] $addresses
		 */
		public function load_addresses($addresses)
		{
			$this->_column_headers = [
				$this->get_columns(),
				[],
				$this->get_sortable_columns(),
			];

			// If no sort, default to title
			$orderby = (!empty($_GET['orderby'])) ? $_GET['orderby'] : 'address';

			// If no order, default to asc
			$order_string = (!empty($_GET['order'])) ? $_GET['order'] : 'asc';
			$order = ($order_string === 'asc' ? 1 : -1);

			usort(
				$addresses,
				function ($a, $b) use ($orderby, $order) {
					return $order * strcmp($a->$orderby, $b->$orderby);
				}
			);

			$this->items = $addresses;
		}

		/**
		 * @param DbLink $item
		 * @param string $column_name
		 *
		 * @return string|int
		 */
		public function column_default($item, $column_name)
		{
			return $item->$column_name;
		}

		/**
		 * @param DbLink $item
		 *
		 * @return string
		 */
		public function column_address($item)
		{
			$action_template = '<a href="?page=%s&action=%s&address=%s">%s</a>';
			$actions = [
				'edit' => sprintf($action_template, $_REQUEST['page'], 'edit', $item->address, __('Edit')),
				'delete' => sprintf($action_template, $_REQUEST['page'], 'delete', $item->address, __('Remove')),
			];
			return $item->address . $this->row_actions($actions);
		}

		/**
		 * @return string[]
		 */
		public function get_bulk_actions()
		{
			return [
				'delete' => __('Delete'),
			];
		}

		/**
		 * @param DbLink $item
		 *
		 * @return string
		 */
		public function column_cb($item)
		{
			return sprintf('<input type="checkbox" name="bitid_row[]" value="%s" />', $item->address);
		}
	}

	/**
	 * Class db_links
	 * @package Puggan
	 * @property int user_id
	 * @property string address
	 * @property string birth
	 * @property string pulse
	 */
	class DbLink
	{
	}

	/**
	 * Class db_nonce
	 * @package Puggan
	 * @property string nonce
	 * @property string address
	 * @property string session_id
	 * @property string user_id
	 * @property string nonce_action
	 * @property string birth
	 */
	class DbNonce
	{
	}

	/**
	 * Class callback_data
	 * @package Puggan
	 * @property string address
	 * @property string signature
	 * @property string uri
	 */
	class CallbackData
	{
		/**
		 * @return string[]
		 */
		static function keys()
		{
			return ['address', 'signature', 'uri'];
		}
	}

	/**
	 * Class AjaxResponse
	 * @package Puggan
	 * @property string address
	 * @property string html
	 * @property int reload
	 * @property int status
	 */
	class AjaxResponse
	{
		public function __toString()
		{
			return json_encode($this, JSON_PRETTY_PRINT) . PHP_EOL;
		}
	}