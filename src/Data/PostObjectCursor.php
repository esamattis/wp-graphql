<?php

namespace WPGraphQL\Data;

/**
 * Post Cursor
 *
 * This class generates the SQL AND operators for cursor based pagination for posts
 *
 * @package WPGraphQL\Data
 */
class PostObjectCursor {

	/**
	 * The global wpdb instance
	 *
	 * @var $wpdb
	 */
	public $wpdb;

	/**
	 * The WP_Query instance
	 *
	 * @var $query
	 */
	public $query;

	/**
	 * The current post id which is our cursor offset
	 *
	 * @var $post_type
	 */
	public $cursor_offset;

	/**
	 * The current post instance
	 *
	 * @var $compare
	 */
	public $cursor_post;

	/**
	 * Default compare for id or date ordering. < or >
	 *
	 * @var $compare
	 */
	public $compare;


	/**
	 * PostCursor constructor.
	 *
	 * @param integer $cursor_offset the post id
	 * @param \WP_Query $query The WP_Query instance
	 */
	public function __construct( $cursor_offset, $query ) {
		global $wpdb;
		$this->wpdb = $wpdb;
		$this->cursor_offset = $cursor_offset;
		$this->query = $query;

		$compare = ! empty( $query->get( 'graphql_cursor_compare' ) ) ? $query->get( 'graphql_cursor_compare' ) : '>';
		$this->compare = in_array( $compare, [ '>', '<' ], true ) ? $compare : '>';

		// Get the $cursor_post
		$this->cursor_post = get_post( $cursor_offset );
	}

	/**
	 * Return the additional AND operators for the where statement
	 */
	public function get_where() {

		/**
		 * If we have no cursor just compare with the ids
		 */
		if ( ! $this->cursor_post ) {
			return $this->compare_with_id();
		}

		$orderby = $this->query->get( 'orderby' );

		if ( ! empty( $orderby ) && is_array( $orderby ) ) {
			/**
			 * Loop through all order keys if it is an array
			 */
			$where = '';
			foreach ( $orderby as $by => $order ) {
				$where .= $this->compare_with( $by, $order );
			}
			return $where;
		} else if ( ! empty( $orderby ) && is_string( $orderby ) ) {
			/**
			 * If $orderby is just a string just compare with it directly
			 */
			$order = ! empty( $this->query->query_vars['order'] ) ? $this->query->query_vars['order'] : 'DESC' ;
			return $this->compare_with( $orderby, $order );
		}

		/**
		 * Default to comparing by ids if no ordering is set
		 */
		return $this->compare_with_id();

	}

	/**
	 * Get AND operator for ID based comparison
	 *
	 * @return string
	 */
	private function compare_with_id() {
		return $this->wpdb->prepare( " AND {$this->wpdb->posts}.ID {$this->compare} %d", $this->cursor_offset );
	}

	/**
	 * Get AND operator for post date based comparison
	 *
	 * @return string
	 */
	private function compare_with_date() {
		return $this->wpdb->prepare(
			" AND {$this->wpdb->posts}.post_date {$this->compare}= %s AND {$this->wpdb->posts}.ID != %d",
			esc_sql( $this->cursor_post->post_date ),
			absint( $this->cursor_offset )
		);
	}

	/**
	 * Get AND operator for given order by key
	 *
	 * @param string    $by The order by key
	 * @param string    $order The order direction ASC or DESC
	 *
	 * @return string
	 */
	private function compare_with( $by, $order ) {
		$order_compare = ( 'ASC' === $order ) ? '>' : '<';

		$post_field = 'post_' . $by;
		$value = $this->cursor_post->{$post_field};

		/**
		 * Compare by the post field if the key matches an value
		 */
		if ( ! empty( $value ) ) {
			return $this->compare_with_post_field( $post_field, $value, $order_compare );
		}

		/**
		 * Find out whether this is a meta key based ordering
		 */
		$meta_key = $this->get_meta_key( $by );
		if ( $meta_key ) {
			return $this->compare_with_meta_field( $meta_key, $order_compare );
		}

		// Default to date compare if no field matches
		return $this->compare_with_date();
	}

	/**
	 * Compare using post field
	 *
	 * @param string    $by Post field key
	 * @param string    $value Value from the post object
	 * @param string    $order_compare comparison string < or >
	 *
	 * @return string
	 */
	private function compare_with_post_field( $by, $value, $order_compare ) {
		return $this->wpdb->prepare( " AND {$this->wpdb->posts}.{$by} {$order_compare} %s", $value );
	}

	/**
	 * Compare with meta key field
	 *
	 * @param string    $meta_key post meta key
	 * @param string    $order_compare The comparison string
	 *
	 * @return string
	 */
	private function compare_with_meta_field( $meta_key, $order_compare ) {
		$meta_type = ! empty( $this->query->query_vars["meta_type"] ) ? esc_sql( $this->query->query_vars["meta_type"] ) : null;
		$meta_value = esc_sql( get_post_meta( $this->cursor_offset, $meta_key, true ) );

		$compare_right = '%s';
		$compare_left = "{$this->wpdb->postmeta}.meta_value";

		/**
		 * Cast the compared values if the query has explicit type set
		 */
		if ( $meta_type ) {
			$meta_type = $this->get_cast_for_type( $meta_type );
			$compare_left = "CAST({$this->wpdb->postmeta}.meta_value AS $meta_type)";
			$compare_right = "CAST(%s AS $meta_type)";
		}

		return $this->wpdb->prepare(
			" AND {$this->wpdb->postmeta}.meta_key = %s AND $compare_left {$order_compare} $compare_right ",
			$meta_key,
			$meta_value
		);

	}

	/**
	 * Get the actual meta key if any
	 *
	 * @param string    $by The order by key
	 *
	 * @return string|null
	 */
	private function get_meta_key( $by ) {

		if ( 'meta_value' === $by ) {
			return ! empty( $this->query->query_vars["meta_key"] ) ? esc_sql( $this->query->query_vars["meta_key"] ) : null;
		}

		/**
		 * Check for the WP 4.2+ style meta clauses
		 * https://make.wordpress.org/core/2015/03/30/query-improvements-in-wp-4-2-orderby-and-meta_query/
		 */
		if ( ! isset( $this->query->query_vars['meta_query'][ $by ] ) ) {
			return null;
		}

		$clause = $this->query->query_vars["meta_query"][ $by ];

		return empty( $clause['key'] ) ? null : $clause['key'];
	}

	/**
	 * Copied from https://github.com/WordPress/WordPress/blob/c4f8bc468db56baa2a3bf917c99cdfd17c3391ce/wp-includes/class-wp-meta-query.php#L272-L296
	 *
	 * It's an intance method. No way to call it without creating the instance?
	 *
	 * Return the appropriate alias for the given meta type if applicable.
	 *
	 * @param string $type MySQL type to cast meta_value.
	 * @return string MySQL type.
	 */
	public function get_cast_for_type( $type = '' ) {
		if ( empty( $type ) ) {
			return 'CHAR';
		}
		$meta_type = strtoupper( $type );
		if ( ! preg_match( '/^(?:BINARY|CHAR|DATE|DATETIME|SIGNED|UNSIGNED|TIME|NUMERIC(?:\(\d+(?:,\s?\d+)?\))?|DECIMAL(?:\(\d+(?:,\s?\d+)?\))?)$/', $meta_type ) ) {
			return 'CHAR';
		}
		if ( 'NUMERIC' == $meta_type ) {
			$meta_type = 'SIGNED';
		}
		return $meta_type;
	}

}