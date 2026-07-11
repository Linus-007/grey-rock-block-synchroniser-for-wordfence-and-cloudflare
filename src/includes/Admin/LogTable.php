<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_List_Table;
use WPCF\FirewallSync\Services\BlockLogger;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class LogTable extends WP_List_Table {
	private array $items_data;

	public function __construct() {
		parent::__construct(
			[
				'singular' => __( 'Firewall Block', 'grey-rock-wordfence-cloudflare-synchroniser' ),
				'plural'   => __( 'Firewall Blocks', 'grey-rock-wordfence-cloudflare-synchroniser' ),
				'ajax'     => false,
			]
		);
	}

	public function prepare_items(): void {
		$per_page    = 10;
		$current_page = 1;

		/*
		 * This is a read-only pagination value. It does not change
		 * WordPress or plugin state.
		 */
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['paged'] ) ) {
			$current_page = max(
				1,
				absint( wp_unslash( $_GET['paged'] ) )
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$total_items = BlockLogger::count();

		$this->items_data = BlockLogger::get_logs(
			$per_page,
			( $current_page - 1 ) * $per_page
		);

		$this->set_pagination_args(
			[
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / $per_page ),
			]
		);

		$this->items = $this->items_data;
	}

	public function get_columns(): array {
		return [
			'ip'         => __( 'IP Address', 'grey-rock-wordfence-cloudflare-synchroniser' ),
			'reason'     => __( 'Reason', 'grey-rock-wordfence-cloudflare-synchroniser' ),
			'created_at' => __( 'Created At', 'grey-rock-wordfence-cloudflare-synchroniser' ),
		];
	}

	public function column_default( $item, $column_name ): string {
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}

	public function no_items(): void {
		echo '<p>' . esc_html__( 'No firewall blocks found.', 'grey-rock-wordfence-cloudflare-synchroniser' ) . '</p>';
	}
}
