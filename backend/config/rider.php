<?php

/**
 * Rider / grab-dispatch configuration for GIAONHANH.
 *
 * Tightens the "grab" model (a paid order is picked by a nearby rider on the
 * public orders.grab channel) so that:
 *  - unassigned orders are only visible to riders within grab_radius_km, and
 *  - the feed is paginated (no unbounded PII blast radius).
 *
 * See docs/red-team-review-2026-08-01.md (hacker #1 — nationwide PII drag).
 */

return [
    // Radius (km) within which an unassigned "picked" order is shown to a rider.
    // 10 km is a sane urban/Vietnam metro default; tune per city.
    'grab_radius_km' => env('RIDER_GRAB_RADIUS_KM', 10),

    // Page size for the grab feed.
    'grab_page_size'  => env('RIDER_GRAB_PAGE_SIZE', 30),
];
