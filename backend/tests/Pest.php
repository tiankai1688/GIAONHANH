<?php

/*
 * Pest bootstrap for the GIAONHANH backend.
 *
 * Every test file under tests/Feature boots the real Laravel application via
 * Tests\TestCase, so `config()` / models / the container are available (this
 * also lets the existing signature/split tests run). Individual files that
 * touch the database opt in with `uses(RefreshDatabase::class);`.
 */

uses(Tests\TestCase::class)->in('Feature');
