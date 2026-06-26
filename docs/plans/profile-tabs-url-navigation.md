# Profile Tabs: Converted to Full URL Navigation

**Date:** 2026-06-26  
**Branch:** v1.0.2  
**Status:** Implemented (quality gates not yet run)

## Summary

Converted profile tabs from reactive Interactivity API switching (all panels
pre-rendered, toggled via `data-wp-bind--hidden`) to full URL-based navigation
(matching the space tabs pattern). Each profile tab now has a clean URL
(`/members/{slug}/posts/`, `/members/{slug}/replies/`, etc.) and only the
active tab's data is loaded server-side.

## Motivation

The previous approach pre-rendered ALL tab panels on every profile page load
(posts, replies, media, likes, followers, following, connections, scheduled,
discussions, about) — wasting bandwidth and DB queries when the user only
sees one tab. Space tabs were already URL-based with conditional rendering;
profile tabs now use the same pattern.

## Files Changed

### 1. `includes/Nav/Providers/ProfileNav.php`

**What changed:**
- Added `use BuddyNext\Core\PageRouter;` import
- Added `tab_url()` private method (mirroring `SpaceNav::tab_url()`):
  ```php
  private function tab_url( int $user_id, string $tab ): string {
      $base = PageRouter::profile_url( $user_id );
      return '' === $tab || 'posts' === $tab ? $base : $base . $tab . '/';
  }
  ```
- Converted ALL tab declarations from `'tab'` (reactive) to `'url'` (full
  navigation):
  - **Metrics** (followers, following, connections): `'tab'` → `'url'`
  - **Primary tabs** (posts, scheduled, replies, media, likes): `'tab'` → `'url'`
  - **Network** (parent + children): `'tab'` removed, `'url'` kept (children
    already had `'url'`)
- Changed URL closures from `static fn(...)` to `fn(...)` so they can call
  `$this->tab_url()` (arrow functions capture `$this` implicitly)

**Effect in nav-bar.php:** Tabs now render as `<a>` links with real `href`
attributes and `aria-current="page"`, not as `<button>` elements with
Interactivity event handlers.

### 2. `templates/profile/view.php`

**What changed:**
- Updated file-level docblock to describe URL-based navigation
- **Social graph member lists** (follower_users, following_users, etc.):
  Initialized to empty arrays; loaded conditionally below
- **Tab-panel data sets** (recent_posts, user_replies, user_likes, etc.):
  Initialized to empty arrays; loaded conditionally below
- Kept `$bn_feed_svc` and `$show_discussions` as always-resolved (needed for
  gating, not data)
- **New conditional data loading section** (after active tab determination):
  ```
  if ( 'posts' === $bn_pf_active_tab )    → load $recent_posts
  if ( 'replies' === $bn_pf_active_tab )  → load $user_replies
  if ( 'likes' === $bn_pf_active_tab )    → load $user_likes
  if ( 'scheduled' === ... && owner )     → load $scheduled_posts
  if ( 'media' === ... && MediaClient )   → load $user_media
  if ( discussions || posts )             → load $jt_discussions
  if ( 'followers' === ... )              → load $follower_users
  if ( 'following' === ... )              → load $following_users
  if ( 'connections' === ... )            → load $connection_users
  ```
- Removed `'activeTab'` from the Interactivity context (`$bn_pf_ctx`) — no
  longer needed since panels are conditionally rendered by PHP, not toggled
  reactively
- Removed `data-wp-init="callbacks.initView"` from the outer `div.bn-pf-stack`
  (initView handled popstate/URL tab syncing)

### 3. `templates/parts/profile-tab-panel.php`

**What changed:**
- Updated file-level docblock to describe URL-based rendering
- Removed `$bn_pf_panel_ctx` helper (no longer needed)
- **Posts panel:** Wrapped in `<?php if ( 'posts' === $bn_pf_active ) : ?>`;
  removed `data-wp-context`, `data-wp-bind--hidden`, static `hidden`
- **Scheduled panel:** Changed existing `<?php if ( $bn_pf_is_owner ) : ?>` to
  `<?php if ( 'scheduled' === $bn_pf_active && $bn_pf_is_owner ) : ?>`;
  removed Interactivity attributes
- **About panel:** Changed existing `<?php if ( '' !== $bn_pf_about_html ) : ?>`
  to `<?php if ( 'about' === $bn_pf_active && '' !== $bn_pf_about_html ) : ?>`;
  removed Interactivity attributes
- **Replies, Media, Likes, Followers, Following, Connections panels:** Each
  wrapped in `<?php if ( 'slug' === $bn_pf_active ) : ?>` / `<?php endif; ?>`;
  all Interactivity attributes removed
- **Discussions panel:** Changed existing `<?php if ( $args['show_discussions'] ) : ?>`
  to `<?php if ( 'discussions' === $bn_pf_active && ... ) : ?>`;
  removed Interactivity attributes
- Each panel keeps its `id` (for `aria-controls` / deep-link compatibility)
  and `data-tab-panel` attribute (for CSS targeting)

### 4. `assets/js/profile/store.js`

**What changed (removed):**
- **`bnProfileBase()`** — helper to read profile base URL from context (only
  used by tab sync code)
- **`syncActiveTabFromUrl()`** — derived active tab from URL path for
  popstate/navigated events
- **`pushTabToUrl()`** — pushed tab slug to address bar via
  `history.pushState()`
- **`state.isActiveTab`** getter — compared panel `tabSlug` with region
  `activeTab` for reactive visibility
- **`state.isActiveBranch`** getter — checked if any child tab was active for
  parent tab highlighting
- **`callbacks.initView`** — registered popstate + buddynext:navigated
  listeners to sync activeTab from URL
- **`actions.setTab`** — set `context.activeTab` and called `pushTabToUrl()`

**What was kept:**
- All other state getters (muteLabel, restrictLabel, blockLabel, 2FA, slug
  validation, etc.)
- `callbacks.initEditGuard` — still needed for profile edit dirty-checking
- All actions (exportMyData, deleteMyAccount, shareProfile, follow, block,
  cover/avatar uploads, 2FA, report, slug checking, etc.)

### 5. `buddynext.php` — `buddynext_profile_tab_panel_open()`

**What changed:**
- Simplified the function: no longer emits `data-wp-context`,
  `data-wp-bind--hidden`, or static `hidden` attribute
- Now outputs only: `<div class="bn-profile-tab-panel" data-tab-panel="{slug}">`
- $active_tab parameter kept for backward compatibility but ignored
- Updated docblock to describe the new behavior

**Impact:** Integration panels (Pro Portfolio, Gamification Achievements,
Jetonomy) that use this helper to add panels via
`buddynext_part_profile_tab_panel_after` will now render without Interactivity
bindings — consistent with core panels.

### 6. `includes/Nav/Providers/SpaceNav.php`

**What changed:**
- Updated file-level docblock: removed the inaccurate "Each tab carries a
  reactive `tab` slug AND a lazy clean-URL `url`" — space tabs only use `url`
- Clarified that profile tabs now use the same pattern

### 7. `templates/parts/nav-metrics.php`

**No change needed.** The template already has three rendering branches:
1. `$bn_m->tab` set → `<button>` with Interactivity (old profile behavior)
2. `$bn_m->url_value` set → `<a>` link (new profile behavior)
3. Neither → `<span>` (display-only)

Since ProfileNav metrics now provide `url` instead of `tab`, they
automatically render as links. The template still supports `tab`-based metrics
for any integration that hasn't migrated.

## Architecture Notes

### The unified rendering framework

The key design insight is that `nav-bar.php` and `nav-metrics.php` already
spoke both rendering languages. The choice was driven purely by which keys
(`tab` vs `url`) the provider registered. Profile tabs were reactive by
choice, not by framework limitation.

### The three nav-bar.php branches (unchanged by this work)

| `tab` set | `url` set | Rendered as |
|-----------|-----------|-------------|
| Yes | Yes | `<a>` with Interactivity bindings + href (no-JS fallback) |
| Yes | No | `<button>` with Interactivity bindings |
| No | Yes | `<a>` with real href, `aria-current="page"` — **URL navigation** |

Profile tabs now fall into branch 3. Space tabs were already there.

### Profile tab URL structure

```
/members/{slug}/              → Posts tab (default)
/members/{slug}/replies/      → Replies tab
/members/{slug}/media/        → Media tab
/members/{slug}/likes/        → Likes tab
/members/{slug}/followers/    → Followers panel
/members/{slug}/following/    → Following panel
/members/{slug}/connections/  → Connections panel
/members/{slug}/about/        → About tab (when content exists)
/members/{slug}/scheduled/    → Scheduled tab (owner only)
/members/{slug}/discussions/  → Discussions tab (Jetonomy)
```

This is handled by the existing rewrite rule in `PageRouter::register_people_rules()`:
```
^members/([^/]+)/([^/]+)/?$ → bn_profile_action=$matches[2]
```

## Remaining Work

1. **Run quality gates:** `bin/check.sh` to verify WPCS, PHPStan, lint pass
   on all changed files
2. **Browser testing:** Verify at `http://buddynext-dev.local`:
   - Profile default tab (no action) → Posts panel renders
   - `/members/{slug}/replies/` → Replies panel renders, only replies data loaded
   - `/members/{slug}/media/` → Media panel renders
   - Deep-link a tab directly → correct panel renders
   - Back/Forward browser buttons → natural URL navigation
   - Metric pills (Followers/Following/Connections) → navigate to correct URL
   - "Network" parent tab → navigates to Connections sub-tab
   - About tab → renders when content exists, 404s gracefully when not
   - Scheduled tab → shows only for profile owner
   - 390px mobile viewport → no horizontal scroll
3. **Verify integration panels:** Check that Pro, Gamification, Jetonomy tabs
   still appear and render correctly
