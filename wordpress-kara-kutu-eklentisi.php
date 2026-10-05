<?php
/**
 * Plugin Name: WordPress Kara Kutu
 * Description: WordPress yönetimindeki önemli etkinlikleri kaydeder ve yöneticilere gösterir.
 * Version: 1.0.0
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Author: WordPress Kara Kutu
 * Text Domain: wordpress-kara-kutu-eklentisi
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WordPress_Kara_Kutu {
	private static $table_name;

	public static function init() {
		global $wpdb;

		self::$table_name = $wpdb->prefix . 'kara_kutu_events';

		add_action( 'admin_menu', array( __CLASS__, 'add_admin_page' ) );
		add_action( 'wp_login', array( __CLASS__, 'login' ), 10, 2 );
		add_action( 'wp_login_failed', array( __CLASS__, 'login_failed' ), 10, 2 );
		add_action( 'wp_logout', array( __CLASS__, 'logout' ) );
		add_action( 'user_register', array( __CLASS__, 'user_registered' ), 10, 1 );
		add_action( 'profile_update', array( __CLASS__, 'profile_updated' ), 10, 1 );
		add_action( 'deleted_user', array( __CLASS__, 'user_deleted' ), 10, 1 );
		add_action( 'set_user_role', array( __CLASS__, 'user_role_set' ), 10, 3 );
		add_action( 'add_user_role', array( __CLASS__, 'user_role_added' ), 10, 2 );
		add_action( 'remove_user_role', array( __CLASS__, 'user_role_removed' ), 10, 2 );

		add_action( 'wp_after_insert_post', array( __CLASS__, 'post_saved' ), 10, 4 );
		add_action( 'before_delete_post', array( __CLASS__, 'post_deleted' ), 10, 2 );
		add_action( 'trashed_post', array( __CLASS__, 'post_trashed' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'post_untrashed' ) );
		add_action( 'add_attachment', array( __CLASS__, 'attachment_added' ) );
		add_action( 'delete_attachment', array( __CLASS__, 'attachment_deleted' ) );

		add_action( 'comment_post', array( __CLASS__, 'comment_added' ), 10, 2 );
		add_action( 'edit_comment', array( __CLASS__, 'comment_edited' ), 10, 1 );
		add_action( 'deleted_comment', array( __CLASS__, 'comment_deleted' ), 10, 1 );
		add_action( 'trashed_comment', array( __CLASS__, 'comment_trashed' ) );
		add_action( 'untrashed_comment', array( __CLASS__, 'comment_untrashed' ) );
		add_action( 'spam_comment', array( __CLASS__, 'comment_spammed' ) );
		add_action( 'unspam_comment', array( __CLASS__, 'comment_unspammed' ) );

		add_action( 'added_option', array( __CLASS__, 'option_added' ), 10, 1 );
		add_action( 'updated_option', array( __CLASS__, 'option_updated' ), 10, 1 );
		add_action( 'deleted_option', array( __CLASS__, 'option_deleted' ), 10, 1 );
		add_action( 'activated_plugin', array( __CLASS__, 'plugin_activated' ), 10, 1 );
		add_action( 'deactivated_plugin', array( __CLASS__, 'plugin_deactivated' ), 10, 1 );
		add_action( 'switch_theme', array( __CLASS__, 'theme_switched' ), 10, 1 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'upgrade_completed' ), 10, 2 );
	}

	public static function activate() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'kara_kutu_events';
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			"CREATE TABLE {$table_name} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_time datetime NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				username varchar(60) NOT NULL DEFAULT '',
				event_name varchar(100) NOT NULL,
				details text NOT NULL,
				object_type varchar(50) NOT NULL DEFAULT '',
				object_id bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY event_time (event_time),
				KEY event_name (event_name)
			) {$charset_collate};"
		);
	}

	public static function login( $username, $user ) {
		self::record( 'user.login', 'Kullanıcı giriş yaptı: ' . $username, 'user', $user->ID );
	}

	public static function login_failed( $username ) {
		self::record( 'user.login_failed', 'Başarısız giriş denemesi: ' . $username, 'user' );
	}

	public static function logout( $user_id ) {
		self::record( 'user.logout', 'Kullanıcı çıkış yaptı.', 'user', $user_id );
	}

	public static function user_registered( $user_id ) {
		self::record( 'user.registered', 'Yeni kullanıcı oluşturuldu.', 'user', $user_id );
	}

	public static function profile_updated( $user_id ) {
		self::record( 'user.profile_updated', 'Kullanıcı profili güncellendi.', 'user', $user_id );
	}

	public static function user_deleted( $user_id ) {
		self::record( 'user.deleted', 'Kullanıcı silindi.', 'user', $user_id );
	}

	public static function user_role_set( $user_id, $role, $old_roles ) {
		self::record( 'user.role_set', 'Kullanıcı rolü değiştirildi: ' . $role, 'user', $user_id );
	}

	public static function user_role_added( $user_id, $role ) {
		self::record( 'user.role_added', 'Kullanıcıya rol eklendi: ' . $role, 'user', $user_id );
	}

	public static function user_role_removed( $user_id, $role ) {
		self::record( 'user.role_removed', 'Kullanıcıdan rol kaldırıldı: ' . $role, 'user', $user_id );
	}

	public static function post_saved( $post_id, $post, $update, $post_before ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$verb  = $update ? 'güncellendi' : 'oluşturuldu';
		$title = wp_strip_all_tags( get_the_title( $post_id ) );
		self::record(
			$update ? 'post.updated' : 'post.created',
			sprintf( 'İçerik %s: %s (durum: %s)', $verb, $title, $post->post_status ),
			$post->post_type,
			$post_id
		);
	}

	public static function post_deleted( $post_id, $post = null ) {
		$post = $post instanceof WP_Post ? $post : get_post( $post_id );
		$type = $post ? $post->post_type : 'post';
		self::record( 'post.deleted', 'İçerik kalıcı olarak silindi.', $type, $post_id );
	}

	public static function post_trashed( $post_id ) {
		self::record( 'post.trashed', 'İçerik çöp kutusuna taşındı.', get_post_type( $post_id ), $post_id );
	}

	public static function post_untrashed( $post_id ) {
		self::record( 'post.untrashed', 'İçerik çöp kutusundan geri alındı.', get_post_type( $post_id ), $post_id );
	}

	public static function attachment_added( $post_id ) {
		self::record( 'media.added', 'Ortam kütüphanesine dosya eklendi.', 'attachment', $post_id );
	}

	public static function attachment_deleted( $post_id ) {
		self::record( 'media.deleted', 'Ortam kütüphanesinden dosya silindi.', 'attachment', $post_id );
	}

	public static function comment_added( $comment_id, $approved ) {
		self::record( 'comment.added', 'Yorum eklendi (onay durumu: ' . $approved . ').', 'comment', $comment_id );
	}

	public static function comment_edited( $comment_id ) {
		self::record( 'comment.edited', 'Yorum düzenlendi.', 'comment', $comment_id );
	}

	public static function comment_deleted( $comment_id ) {
		self::record( 'comment.deleted', 'Yorum silindi.', 'comment', $comment_id );
	}

	public static function comment_trashed( $comment_id ) {
		self::record( 'comment.trashed', 'Yorum çöp kutusuna taşındı.', 'comment', $comment_id );
	}

	public static function comment_untrashed( $comment_id ) {
		self::record( 'comment.untrashed', 'Yorum çöp kutusundan geri alındı.', 'comment', $comment_id );
	}

	public static function comment_spammed( $comment_id ) {
		self::record( 'comment.spammed', 'Yorum spam olarak işaretlendi.', 'comment', $comment_id );
	}

	public static function comment_unspammed( $comment_id ) {
		self::record( 'comment.unspammed', 'Yorum spam durumundan çıkarıldı.', 'comment', $comment_id );
	}

	public static function option_added( $option ) {
		self::record_option_change( 'option.added', $option );
	}

	public static function option_updated( $option ) {
		self::record_option_change( 'option.updated', $option );
	}

	public static function option_deleted( $option ) {
		self::record_option_change( 'option.deleted', $option );
	}

	public static function plugin_activated( $plugin ) {
		self::record( 'plugin.activated', 'Eklenti etkinleştirildi: ' . $plugin, 'plugin' );
	}

	public static function plugin_deactivated( $plugin ) {
		self::record( 'plugin.deactivated', 'Eklenti devre dışı bırakıldı: ' . $plugin, 'plugin' );
	}

	public static function theme_switched( $new_name ) {
		self::record( 'theme.switched', 'Tema değiştirildi: ' . $new_name, 'theme' );
	}

	public static function upgrade_completed( $upgrader, $options ) {
		$type = isset( $options['type'] ) ? sanitize_key( $options['type'] ) : 'unknown';
		self::record( 'core.upgrade_completed', 'WordPress güncelleme işlemi tamamlandı (' . $type . ').', 'upgrade' );
	}

	private static function record_option_change( $event_name, $option ) {
		if ( 0 === strpos( $option, '_transient_' ) || 0 === strpos( $option, '_site_transient_' ) ) {
			return;
		}

		self::record( $event_name, 'Ayar değiştirildi: ' . $option, 'option' );
	}

	private static function record( $event_name, $details, $object_type = '', $object_id = 0 ) {
		global $wpdb;

		static $is_recording = false;
		if ( $is_recording || empty( self::$table_name ) ) {
			return;
		}

		$is_recording = true;
		$user         = wp_get_current_user();

		$wpdb->insert(
			self::$table_name,
			array(
				'event_time' => current_time( 'mysql', true ),
				'user_id'    => get_current_user_id(),
				'username'   => $user->exists() ? $user->user_login : '',
				'event_name' => sanitize_text_field( $event_name ),
				'details'    => sanitize_textarea_field( $details ),
				'object_type' => sanitize_key( $object_type ),
				'object_id'  => absint( $object_id ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%d' )
		);
		$is_recording = false;
	}

	public static function add_admin_page() {
		add_menu_page(
			'Kara Kutu',
			'Kara Kutu',
			'manage_options',
			'wordpress-kara-kutu',
			array( __CLASS__, 'render_admin_page' ),
			'dashicons-visibility'
		);
	}

	public static function render_admin_page() {
		global $wpdb;

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Bu sayfayı görüntüleme izniniz yok.', 'wordpress-kara-kutu-eklentisi' ) );
		}

		$per_page    = 50;
		$current_page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$total_events = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . self::$table_name );
		$total_pages  = max( 1, (int) ceil( $total_events / $per_page ) );
		$current_page = min( $current_page, $total_pages );
		$offset       = ( $current_page - 1 ) * $per_page;
		$events       = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT event_time, username, event_name, details, object_type, object_id
				FROM " . self::$table_name . "
				ORDER BY id DESC
				LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Kara Kutu', 'wordpress-kara-kutu-eklentisi' ); ?></h1>
			<p><?php echo esc_html__( 'Kayıtlar yalnızca görüntülenebilir; bu eklentide kayıt silme özelliği yoktur.', 'wordpress-kara-kutu-eklentisi' ); ?></p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Tarih (UTC)', 'wordpress-kara-kutu-eklentisi' ); ?></th>
						<th><?php echo esc_html__( 'Kullanıcı', 'wordpress-kara-kutu-eklentisi' ); ?></th>
						<th><?php echo esc_html__( 'Etkinlik', 'wordpress-kara-kutu-eklentisi' ); ?></th>
						<th><?php echo esc_html__( 'Ayrıntı', 'wordpress-kara-kutu-eklentisi' ); ?></th>
						<th><?php echo esc_html__( 'Nesne', 'wordpress-kara-kutu-eklentisi' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( $events ) : ?>
						<?php foreach ( $events as $event ) : ?>
							<tr>
								<td><?php echo esc_html( $event['event_time'] ); ?></td>
								<td><?php echo esc_html( $event['username'] ? $event['username'] : '—' ); ?></td>
								<td><?php echo esc_html( $event['event_name'] ); ?></td>
								<td><?php echo esc_html( $event['details'] ); ?></td>
								<td><?php echo esc_html( trim( $event['object_type'] . ' #' . $event['object_id'], ' #' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php else : ?>
						<tr><td colspan="5"><?php echo esc_html__( 'Henüz kayıtlı etkinlik yok.', 'wordpress-kara-kutu-eklentisi' ); ?></td></tr>
					<?php endif; ?>
				</tbody>
			</table>
			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav">
					<div class="tablenav-pages">
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'      => add_query_arg( 'paged', '%#%' ),
									'format'    => '',
									'current'   => $current_page,
									'total'     => $total_pages,
									'prev_text' => '&laquo;',
									'next_text' => '&raquo;',
								)
							)
						);
						?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}

register_activation_hook( __FILE__, array( 'WordPress_Kara_Kutu', 'activate' ) );
add_action( 'plugins_loaded', array( 'WordPress_Kara_Kutu', 'init' ) );
