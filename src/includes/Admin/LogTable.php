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
	private array $items_data = [];

	private array $filters = [
		'ip'        => '',
		'reason'    => '',
		'date_from' => '',
		'date_to'   => '',
	];

	private string $orderby = 'created_at';

	private string $order = 'DESC';

	public function __construct() {
		parent::__construct(
			[
				'singular' => __( 'Synchronisation Record', 'grey-rock-block-synchroniser-for-wordfence-and-cloudflare' ),
				'plural'   => __( 'Synchronisation Records', 'grey-rock-block-synchroniser-for-wordfence-and-cloudflare' ),
				'ajax'     => false,
			]
		);
	}

	public function prepare_items(): void {
		$per_page     = 10;
		$current_page = 1;

		/*
		 * These are read-only query parameters used to display, sort and
		 * filter synchronisation records. They do not change plugin state.
		 */
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['paged'] ) ) {
			$current_page = max(
				1,
				absint( wp_unslash( $_GET['paged'] ) )
			);
		}

		$this->filters = [
			'ip'        => isset( $_GET['ip'] )
				? sanitize_text_field( wp_unslash( $_GET['ip'] ) )
				: '',
			'reason'    => isset( $_GET['reason'] )
				? sanitize_text_field( wp_unslash( $_GET['reason'] ) )
				: '',
			'date_from' => isset( $_GET['date_from'] )
				? self::sanitize_date(
					sanitize_text_field(
						wp_unslash( $_GET['date_from'] )
					)
				)
				: '',
			'date_to'   => isset( $_GET['date_to'] )
				? self::sanitize_date(
					sanitize_text_field(
						wp_unslash( $_GET['date_to'] )
					)
				)
				: '',
		];

		$requested_orderby = isset( $_GET['orderby'] )
			? sanitize_key( wp_unslash( $_GET['orderby'] ) )
			: 'created_at';

		if ( in_array( $requested_orderby, [ 'ip', 'reason', 'created_at' ], true ) ) {
			$this->orderby = $requested_orderby;
		}

		$requested_order = isset( $_GET['order'] )
			? strtoupper( sanitize_key( wp_unslash( $_GET['order'] ) ) )
			: 'DESC';

		if ( in_array( $requested_order, [ 'ASC', 'DESC' ], true ) ) {
			$this->order = $requested_order;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$total_items = BlockLogger::count( $this->filters );

		$this->items_data = BlockLogger::get_logs(
			$per_page,
			( $current_page - 1 ) * $per_page,
			$this->filters,
			$this->orderby,
			$this->order
		);

		$this->set_pagination_args(
			[
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / $per_page ),
			]
		);

		$this->_column_headers = [
			$this->get_columns(),
			[],
			$this->get_sortable_columns(),
			'ip',
		];

		$this->items = $this->items_data;
	}

	public function get_columns(): array {
		return [
			'ip'         => __( 'IP Address', 'grey-rock-block-synchroniser-for-wordfence-and-cloudflare' ),
			'reason'     => __( 'Reason', 'grey-rock-block-synchroniser-for-wordfence-and-cloudflare' ),
			'created_at' => __( 'Recorded', 'grey-rock-block-synchroniser-for-wordfence-and-cloudflare' ),
		];
	}

	protected function get_sortable_columns(): array {
		return [
			'ip'         => [ 'ip', false ],
			'reason'     => [ 'reason', false ],
			'created_at' => [ 'created_at', true ],
		];
	}

	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}
		?>
		<div class="alignleft actions">
			<label class="screen-reader-text" for="grey-rock-filter-ip">
				<?php
				echo esc_html__(
					'Filter by IP address',
					'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
				);
				?>
			</label>
			<input
				type="text"
				id="grey-rock-filter-ip"
				name="ip"
				value="<?php echo esc_attr( $this->filters['ip'] ); ?>"
				placeholder="<?php echo esc_attr__( 'IP Address', 'grey-rock-block-synchroniser-for-wordfence-and-cloudflare' ); ?>"
			/>

			<label class="screen-reader-text" for="grey-rock-filter-reason">
				<?php
				echo esc_html__(
					'Filter by reason',
					'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
				);
				?>
			</label>
			<input
				type="text"
				id="grey-rock-filter-reason"
				name="reason"
				value="<?php echo esc_attr( $this->filters['reason'] ); ?>"
				placeholder="<?php echo esc_attr__( 'Reason contains', 'grey-rock-block-synchroniser-for-wordfence-and-cloudflare' ); ?>"
			/>

			<label for="grey-rock-filter-date-from">
				<?php
				echo esc_html__(
					'From',
					'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
				);
				?>
			</label>
			<input
				type="date"
				id="grey-rock-filter-date-from"
				name="date_from"
				value="<?php echo esc_attr( $this->filters['date_from'] ); ?>"
			/>

			<label for="grey-rock-filter-date-to">
				<?php
				echo esc_html__(
					'To',
					'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
				);
				?>
			</label>
			<input
				type="date"
				id="grey-rock-filter-date-to"
				name="date_to"
				value="<?php echo esc_attr( $this->filters['date_to'] ); ?>"
			/>

			<?php
			submit_button(
				__( 'Filter', 'grey-rock-block-synchroniser-for-wordfence-and-cloudflare' ),
				'secondary',
				'filter_action',
				false
			);
			?>

			<a
				class="button"
				href="<?php echo esc_url( admin_url( 'admin.php?page=firewall-sync-log' ) ); ?>"
			>
				<?php
				echo esc_html__(
					'Reset',
					'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
				);
				?>
			</a>
		</div>
		<?php
	}

	public function column_default( $item, $column_name ): string {
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}

	public function no_items(): void {
		echo '<p>' .
			esc_html__(
				'No synchronisation records were found for this site.',
				'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
			) .
			'</p>';
	}

	private static function sanitize_date( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = sanitize_text_field( $value );

		$date = \DateTimeImmutable::createFromFormat(
			'!Y-m-d',
			$value
		);

		if (
			! $date
			|| $date->format( 'Y-m-d' ) !== $value
		) {
			return '';
		}

		return $value;
	}
}
