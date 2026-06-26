<?php
/**
 * BuddyNext — Profile nav provider.
 *
 * Registers the built-in navigation for the member-profile surface into the
 * NavRegistry: the relationship metric row (Followers / Following / Connections)
 * and the primary content tabs (Posts / Scheduled / Replies / Media / Likes).
 * Integration-owned items (Jetonomy Discussions, Gamification Achievements) are
 * registered by their own bridges through the same `buddynext_register_nav` seam,
 * so this provider owns ONLY the core surface — no partner coupling.
 *
 * Counts and visibility are lazy callables resolved against the live NavContext,
 * so one declaration serves every profile (self or viewer) without per-view
 * branching in the template.
 *
 * @package BuddyNext\Nav\Providers
 */

declare( strict_types=1 );

namespace BuddyNext\Nav\Providers;

use BuddyNext\Core\PageRouter;
use BuddyNext\Media\Galleries;
use BuddyNext\Media\MediaClient;
use BuddyNext\Nav\NavContext;
use BuddyNext\Nav\NavRegistry;

/**
 * Core nav provider for the `profile` surface.
 */
final class ProfileNav {

	/**
	 * Hook the provider onto the one-time registration action.
	 */
	public function register(): void {
		add_action( 'buddynext_register_nav', array( $this, 'register_items' ) );
	}

	/**
	 * Register the core profile metrics + primary tabs.
	 *
	 * @param NavRegistry $registry The shared registry.
	 */
	public function register_items( NavRegistry $registry ): void {
		foreach ( $this->metrics() as $item ) {
			$registry->register( $item );
		}
		foreach ( $this->primary_tabs() as $item ) {
			$registry->register( $item );
		}
		foreach ( $this->network() as $item ) {
			$registry->register( $item );
		}
	}

	/**
	 * Clean-URL builder for a profile tab — /members/{slug}/{tab}/ (posts = the base).
	 *
	 * @param int    $user_id User ID.
	 * @param string $tab     Tab slug ('' or 'posts' = the base/profile URL).
	 * @return string
	 */
	private function tab_url( int $user_id, string $tab ): string {
		$base = PageRouter::profile_url( $user_id );
		return '' === $tab || 'posts' === $tab ? $base : $base . $tab . '/';
	}

	/**
	 * The relationship metric row — display counts that deep-link to their list
	 * panel via full URL navigation.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function metrics(): array {
		return array(
			array(
				'id'          => 'followers',
				'surface'     => 'profile',
				'layer'       => 'metric',
				'label'       => __( 'Followers', 'buddynext' ),
				'count_label' => static fn( int $n ): string => _n( 'Follower', 'Followers', $n, 'buddynext' ),
				'url'         => fn( NavContext $c ): string => $this->tab_url( $c->subject_id, 'followers' ),
				'priority'    => 10,
				'count'       => static fn( NavContext $c ): int => (int) buddynext_service( 'follows' )->follower_count( $c->subject_id ),
			),
			array(
				'id'       => 'following',
				'surface'  => 'profile',
				'layer'    => 'metric',
				'label'    => __( 'Following', 'buddynext' ),
				'url'      => fn( NavContext $c ): string => $this->tab_url( $c->subject_id, 'following' ),
				'priority' => 20,
				'count'    => static fn( NavContext $c ): int => (int) buddynext_service( 'follows' )->following_count( $c->subject_id ),
			),
			array(
				'id'          => 'connections',
				'surface'     => 'profile',
				'layer'       => 'metric',
				'label'       => __( 'Connections', 'buddynext' ),
				'count_label' => static fn( int $n ): string => _n( 'Connection', 'Connections', $n, 'buddynext' ),
				'url'         => fn( NavContext $c ): string => $this->tab_url( $c->subject_id, 'connections' ),
				'priority'    => 30,
				'count'       => static fn( NavContext $c ): int => (int) buddynext_service( 'connections' )->connection_count( $c->subject_id ),
			),
		);
	}

	/**
	 * The primary content tabs. Posts owns the post count (so the dedupe rule
	 * drops any metric that would duplicate it). Scheduled is owner-only; Media
	 * is gated on the media engine being active. Each tab is a full URL navigation
	 * (not reactive in-page switching).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function primary_tabs(): array {
		return array(
			array(
				'id'       => 'posts',
				'surface'  => 'profile',
				'layer'    => 'primary',
				'label'    => __( 'Posts', 'buddynext' ),
				'url'      => fn( NavContext $c ): string => $this->tab_url( $c->subject_id, 'posts' ),
				'priority' => 10,
				'count'    => static fn( NavContext $c ): int => (int) buddynext_service( 'post_service' )->user_post_count( $c->subject_id ),
			),
			array(
				'id'        => 'scheduled',
				'surface'   => 'profile',
				'layer'     => 'primary',
				'label'     => __( 'Scheduled', 'buddynext' ),
				'url'       => fn( NavContext $c ): string => $this->tab_url( $c->subject_id, 'scheduled' ),
				'priority'  => 15,
				'after'     => 'posts',
				'condition' => static fn( NavContext $c ): bool => $c->is_self(),
				'count'     => static fn( NavContext $c ): int => (int) buddynext_service( 'post_service' )->user_scheduled_count( $c->subject_id ),
			),
			array(
				'id'       => 'replies',
				'surface'  => 'profile',
				'layer'    => 'primary',
				'label'    => __( 'Replies', 'buddynext' ),
				'url'      => fn( NavContext $c ): string => $this->tab_url( $c->subject_id, 'replies' ),
				'priority' => 30,
				'count'    => static fn( NavContext $c ): int => (int) buddynext_service( 'post_service' )->reply_count( $c->subject_id ),
			),
			array(
				'id'        => 'media',
				'surface'   => 'profile',
				'layer'     => 'primary',
				'label'     => __( 'Media', 'buddynext' ),
				'url'       => fn( NavContext $c ): string => $this->tab_url( $c->subject_id, 'media' ),
				'priority'  => 40,
				'condition' => static fn(): bool => MediaClient::available(),
				'count'     => static fn( NavContext $c ): int => (int) Galleries::user_media_count( $c->subject_id, $c->viewer_id ),
			),
			array(
				'id'       => 'likes',
				'surface'  => 'profile',
				'layer'    => 'primary',
				'label'    => __( 'Likes', 'buddynext' ),
				'url'      => fn( NavContext $c ): string => $this->tab_url( $c->subject_id, 'likes' ),
				'priority' => 50,
				'count'    => static fn( NavContext $c ): int => (int) buddynext_service( 'post_service' )->reaction_count( $c->subject_id ),
			),
		);
	}

	/**
	 * The "Network" primary tab and its one-level sub-nav (Connections /
	 * Followers / Following). The parent links to the Connections sub-tab; the
	 * relationship metric pills in the hero deep-link to the same sub-tab targets,
	 * so the hero counts and this section stay in lockstep.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function network(): array {
		return array(
			array(
				'id'       => 'network',
				'surface'  => 'profile',
				'layer'    => 'primary',
				'label'    => __( 'Network', 'buddynext' ),
				'url'      => fn( NavContext $c ): string => $this->tab_url( $c->subject_id, 'connections' ),
				'icon'     => 'users',
				'priority' => 55,
			),
			array(
				'id'       => 'connections',
				'surface'  => 'profile',
				'layer'    => 'primary',
				'parent'   => 'network',
				'label'    => __( 'Connections', 'buddynext' ),
				'url'      => fn( NavContext $c ): string => $this->tab_url( $c->subject_id, 'connections' ),
				'priority' => 10,
				'count'    => static fn( NavContext $c ): int => (int) buddynext_service( 'connections' )->connection_count( $c->subject_id ),
			),
			array(
				'id'       => 'followers',
				'surface'  => 'profile',
				'layer'    => 'primary',
				'parent'   => 'network',
				'label'    => __( 'Followers', 'buddynext' ),
				'url'      => fn( NavContext $c ): string => $this->tab_url( $c->subject_id, 'followers' ),
				'priority' => 20,
				'count'    => static fn( NavContext $c ): int => (int) buddynext_service( 'follows' )->follower_count( $c->subject_id ),
			),
			array(
				'id'       => 'following',
				'surface'  => 'profile',
				'layer'    => 'primary',
				'parent'   => 'network',
				'label'    => __( 'Following', 'buddynext' ),
				'url'      => fn( NavContext $c ): string => $this->tab_url( $c->subject_id, 'following' ),
				'priority' => 30,
				'count'    => static fn( NavContext $c ): int => (int) buddynext_service( 'follows' )->following_count( $c->subject_id ),
			),
		);
	}
}
