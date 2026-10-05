<?php
/**
 * Plugin Name: Kara Kutu
 * Description: WordPress üzerinde yapılan tüm işlemleri silinemez biçimde kayıt eder ve yönetim panelinde sayfalı olarak gösterir.
 * Version: 1.0.0
 * Text Domain: kara-kutu
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Kara_Kutu {

	const DB_VERSION = '1';
	const PER_PAGE   = 50;

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'kara_kutu';
	}

	public static function init() {
		register_activation_hook( __FILE__, array( __CLASS__, 'install' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_install' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		self::register_hooks();
	}

	public static function maybe_install() {
		if ( get_option( 'kara_kutu_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE $table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			log_time datetime NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_login varchar(60) NOT NULL DEFAULT '',
			ip varchar(64) NOT NULL DEFAULT '',
			action varchar(64) NOT NULL DEFAULT '',
			object_type varchar(40) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			object_name text NOT NULL,
			details text NOT NULL,
			PRIMARY KEY  (id),
			KEY log_time (log_time),
			KEY user_id (user_id),
			KEY action (action)
		) $charset;" );

		// Veritabanı düzeyinde değiştirme/silme engeli (yetki varsa).
		$suppress = $wpdb->suppress_errors( true );
		foreach ( array( 'UPDATE', 'DELETE' ) as $op ) {
			$name = $table . '_no_' . strtolower( $op );
			$wpdb->query( "DROP TRIGGER IF EXISTS `$name`" );
			$wpdb->query( "CREATE TRIGGER `$name` BEFORE $op ON `$table` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Kara Kutu kayitlari degistirilemez ve silinemez'" );
		}
		$wpdb->suppress_errors( $suppress );
		update_option( 'kara_kutu_db_version', self::DB_VERSION );
	}

	private static function ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	public static function log( $action, $object_type = '', $object_id = 0, $object_name = '', $details = '', $user = null ) {
		global $wpdb;
		if ( null === $user ) {
			$user = wp_get_current_user();
		}
		$wpdb->insert(
			self::table(),
			array(
				'log_time'    => current_time( 'mysql' ),
				'user_id'     => $user && $user->exists() ? (int) $user->ID : 0,
				'user_login'  => $user && $user->exists() ? $user->user_login : '',
				'ip'          => self::ip(),
				'action'      => $action,
				'object_type' => $object_type,
				'object_id'   => (int) $object_id,
				'object_name' => (string) $object_name,
				'details'     => is_scalar( $details ) ? (string) $details : wp_json_encode( $details ),
			)
		);
	}

	private static function skip_post( $post ) {
		return ! $post || in_array( $post->post_type, array( 'revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache' ), true )
			|| 'auto-draft' === $post->post_status;
	}

	private static function register_hooks() {
		// Yazı / sayfa.
		add_action( 'wp_insert_post', function ( $id, $post, $update ) {
			if ( self::skip_post( $post ) || wp_is_post_revision( $id ) || wp_is_post_autosave( $id ) ) {
				return;
			}
			self::log( $update ? 'post_updated' : 'post_created', $post->post_type, $id, $post->post_title, 'status=' . $post->post_status );
		}, 10, 3 );
		add_action( 'wp_trash_post', function ( $id ) {
			$p = get_post( $id );
			if ( ! self::skip_post( $p ) ) {
				self::log( 'post_trashed', $p->post_type, $id, $p->post_title );
			}
		} );
		add_action( 'untrashed_post', function ( $id ) {
			$p = get_post( $id );
			if ( ! self::skip_post( $p ) ) {
				self::log( 'post_restored', $p->post_type, $id, $p->post_title );
			}
		} );
		add_action( 'before_delete_post', function ( $id ) {
			$p = get_post( $id );
			if ( ! self::skip_post( $p ) ) {
				self::log( 'post_deleted', $p->post_type, $id, $p->post_title );
			}
		} );
		add_action( 'load-edit.php', function () {
			if ( isset( $_REQUEST['delete_all'] ) ) {
				self::log( 'trash_emptied', 'post', 0, '', isset( $_REQUEST['post_type'] ) ? sanitize_key( $_REQUEST['post_type'] ) : 'post' );
			}
		} );
		add_action( 'set_object_terms', function ( $id, $terms, $tt_ids, $taxonomy, $append, $old ) {
			$p = get_post( $id );
			if ( self::skip_post( $p ) || wp_is_post_revision( $id ) || array_map( 'intval', $tt_ids ) === array_map( 'intval', (array) $old ) ) {
				return;
			}
			self::log( 'post_terms_set', $p->post_type, $id, $p->post_title, $taxonomy . ': ' . implode( ',', array_map( 'strval', $terms ) ) );
		}, 10, 6 );

		// Kategori / etiket / terimler.
		add_action( 'created_term', function ( $id, $tt, $tax ) {
			$t = get_term( $id, $tax );
			self::log( 'term_created', $tax, $id, is_wp_error( $t ) || ! $t ? '' : $t->name );
		}, 10, 3 );
		add_action( 'edited_term', function ( $id, $tt, $tax ) {
			$t = get_term( $id, $tax );
			self::log( 'term_updated', $tax, $id, is_wp_error( $t ) || ! $t ? '' : $t->name );
		}, 10, 3 );
		add_action( 'delete_term', function ( $id, $tt, $tax, $deleted ) {
			self::log( 'term_deleted', $tax, $id, isset( $deleted->name ) ? $deleted->name : '' );
		}, 10, 4 );

		// Medya.
		add_action( 'add_attachment', function ( $id ) {
			self::log( 'media_added', 'attachment', $id, get_the_title( $id ) );
		} );
		add_action( 'delete_attachment', function ( $id ) {
			self::log( 'media_deleted', 'attachment', $id, get_the_title( $id ) );
		} );

		// Yorumlar.
		add_action( 'wp_insert_comment', function ( $id, $c ) {
			self::log( 'comment_created', 'comment', $id, get_the_title( $c->comment_post_ID ), 'author=' . $c->comment_author . ', status=' . $c->comment_approved );
		}, 10, 2 );
		add_action( 'edit_comment', function ( $id ) {
			$c = get_comment( $id );
			self::log( 'comment_updated', 'comment', $id, $c ? get_the_title( $c->comment_post_ID ) : '' );
		} );
		add_action( 'transition_comment_status', function ( $new, $old, $c ) {
			if ( $new === $old ) {
				return;
			}
			self::log( 'comment_' . $new, 'comment', $c->comment_ID, get_the_title( $c->comment_post_ID ), "$old -> $new" );
		}, 10, 3 );
		add_action( 'delete_comment', function ( $id ) {
			$c = get_comment( $id );
			self::log( 'comment_deleted', 'comment', $id, $c ? get_the_title( $c->comment_post_ID ) : '' );
		} );

		// Giriş / çıkış / şifre / profil.
		add_action( 'wp_login', function ( $login, $user ) {
			self::log( 'login', 'user', $user->ID, $login, '', $user );
		}, 10, 2 );
		add_action( 'wp_logout', function ( $uid ) {
			$u = get_userdata( $uid );
			self::log( 'logout', 'user', $uid, $u ? $u->user_login : '', '', $u ?: null );
		} );
		add_action( 'wp_login_failed', function ( $login ) {
			self::log( 'login_failed', 'user', 0, $login, '', new WP_User( 0 ) );
		} );
		add_action( 'retrieve_password', function ( $login ) {
			self::log( 'password_lost', 'user', 0, $login, '', new WP_User( 0 ) );
		} );
		add_action( 'after_password_reset', function ( $user ) {
			self::log( 'password_reset', 'user', $user->ID, $user->user_login, '', $user );
		} );
		add_action( 'profile_update', function ( $id ) {
			$u = get_userdata( $id );
			self::log( 'profile_updated', 'user', $id, $u ? $u->user_login : '' );
		} );
		add_action( 'user_register', function ( $id ) {
			$u = get_userdata( $id );
			self::log( 'user_created', 'user', $id, $u ? $u->user_login : '' );
		} );
		add_action( 'delete_user', function ( $id ) {
			$u = get_userdata( $id );
			self::log( 'user_deleted', 'user', $id, $u ? $u->user_login : '' );
		} );
		add_action( 'set_user_role', function ( $id, $role, $old ) {
			$u = get_userdata( $id );
			self::log( 'user_role_changed', 'user', $id, $u ? $u->user_login : '', implode( ',', $old ) . ' -> ' . $role );
		}, 10, 3 );

		// Güncellemeler.
		add_action( 'load-update-core.php', function () {
			self::log( 'update_page_viewed', 'update', 0, 'update-core.php' );
		} );
		add_action( 'upgrader_process_complete', function ( $upgrader, $data ) {
			$type   = isset( $data['type'] ) ? $data['type'] : '';
			$action = isset( $data['action'] ) ? $data['action'] : '';
			$items  = array();
			if ( ! empty( $data['plugins'] ) ) {
				$items = (array) $data['plugins'];
			} elseif ( ! empty( $data['themes'] ) ) {
				$items = (array) $data['themes'];
			} elseif ( ! empty( $data['plugin'] ) ) {
				$items = array( $data['plugin'] );
			} elseif ( ! empty( $data['translations'] ) ) {
				foreach ( (array) $data['translations'] as $t ) {
					$items[] = $t['type'] . ':' . $t['slug'] . ':' . $t['language'];
				}
			} elseif ( 'core' === $type ) {
				$items = array( get_bloginfo( 'version' ) );
			}
			self::log( $type . '_' . $action, $type, 0, implode( ', ', $items ) );
		}, 10, 2 );
		add_action( 'activated_plugin', function ( $p ) {
			self::log( 'plugin_activated', 'plugin', 0, $p );
		} );
		add_action( 'deactivated_plugin', function ( $p ) {
			self::log( 'plugin_deactivated', 'plugin', 0, $p );
		} );
		add_action( 'deleted_plugin', function ( $p ) {
			self::log( 'plugin_deleted', 'plugin', 0, $p );
		} );
		add_action( 'switch_theme', function ( $name ) {
			self::log( 'theme_switched', 'theme', 0, $name );
		} );
		add_action( 'deleted_theme', function ( $s ) {
			self::log( 'theme_deleted', 'theme', 0, $s );
		} );

		// İçe / dışa aktarma.
		add_action( 'export_wp', function ( $args ) {
			self::log( 'export', 'tools', 0, '', $args );
		} );
		add_action( 'import_start', function () {
			self::log( 'import_started', 'tools' );
		} );
		add_action( 'import_end', function () {
			self::log( 'import_finished', 'tools' );
		} );

		// Ayarlar.
		add_action( 'updated_option', function ( $option ) {
			if ( in_array( $option, array( 'blogname', 'blogdescription', 'siteurl', 'home', 'admin_email', 'users_can_register', 'default_role', 'permalink_structure' ), true ) ) {
				self::log( 'setting_changed', 'option', 0, $option );
			}
		} );
	}

	public static function menu() {
		add_menu_page( 'Kara Kutu', 'Kara Kutu', 'manage_options', 'kara-kutu', array( __CLASS__, 'page' ), 'dashicons-media-text', 80 );
	}

	public static function page() {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Yetkiniz yok.', 'kara-kutu' ) );
		}
		$table = self::table();
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged = min( $paged, $pages );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table ORDER BY id DESC LIMIT %d OFFSET %d", self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE ) );

		echo '<div class="wrap"><h1>Kara Kutu</h1>';
		echo '<p>' . esc_html( sprintf( 'Toplam %d kayıt. Kayıtlar silinemez ve değiştirilemez.', $total ) ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>#</th><th>Tarih / Saat</th><th>Kullanıcı</th><th>IP</th><th>İşlem</th><th>Tür</th><th>Nesne</th><th>Ayrıntı</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="8">Kayıt yok.</td></tr>';
		}
		foreach ( $rows as $r ) {
			$user = $r->user_login ? $r->user_login . ' (#' . $r->user_id . ')' : '-';
			$obj  = $r->object_name . ( $r->object_id ? ' (#' . $r->object_id . ')' : '' );
			printf(
				'<tr><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				(int) $r->id,
				esc_html( $r->log_time ),
				esc_html( $user ),
				esc_html( $r->ip ),
				esc_html( $r->action ),
				esc_html( $r->object_type ),
				esc_html( $obj ),
				esc_html( $r->details )
			);
		}
		echo '</tbody></table>';
		echo '<div class="tablenav bottom"><div class="tablenav-pages">';
		echo wp_kses_post( (string) paginate_links( array(
			'base'      => add_query_arg( 'paged', '%#%' ),
			'format'    => '',
			'current'   => $paged,
			'total'     => $pages,
			'prev_text' => '&laquo;',
			'next_text' => '&raquo;',
		) ) );
		echo '</div></div></div>';
	}
}

Kara_Kutu::init();
