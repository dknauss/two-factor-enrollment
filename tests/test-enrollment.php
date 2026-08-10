<?php
/**
 * Tests for enrollment predicates.
 *
 * @package Two_Factor_Enrollment
 * @group enrollment
 */
class Tests_Two_Factor_Enrollment extends WP_UnitTestCase {

	private $user_id;

	public function set_up() {
		parent::set_up();
		$this->user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
	}

	public function tear_down() {
		remove_all_filters( 'two_factor_enrollment_required' );
		remove_all_filters( 'two_factor_enrollment_deferrable' );
		remove_all_filters( 'two_factor_providers' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_is_required_defaults_to_false() {
		$this->assertFalse( Two_Factor_Enrollment::is_required( $this->user_id ) );
	}

	public function test_is_required_honors_filter() {
		add_filter( 'two_factor_enrollment_required', '__return_true' );
		$this->assertTrue( Two_Factor_Enrollment::is_required( $this->user_id ) );
	}

	public function test_is_required_filter_receives_user_object() {
		$seen = null;
		add_filter(
			'two_factor_enrollment_required',
			function ( $required, $user ) use ( &$seen ) {
				$seen = $user;
				return $required;
			},
			10,
			2
		);

		Two_Factor_Enrollment::is_required( $this->user_id );

		$this->assertInstanceOf( 'WP_User', $seen );
		$this->assertSame( $this->user_id, $seen->ID );
	}

	public function test_is_required_false_for_invalid_user() {
		add_filter( 'two_factor_enrollment_required', '__return_true' );
		$this->assertFalse( Two_Factor_Enrollment::is_required( 999999 ) );
	}

	public function test_is_deferrable_defaults_to_false() {
		$this->assertFalse( Two_Factor_Enrollment::is_deferrable( $this->user_id ) );
	}

	public function test_is_deferrable_honors_filter() {
		add_filter( 'two_factor_enrollment_deferrable', '__return_true' );
		$this->assertTrue( Two_Factor_Enrollment::is_deferrable( $this->user_id ) );
	}

	public function test_is_complete_false_without_provider() {
		$this->assertFalse( Two_Factor_Enrollment::is_complete( $this->user_id ) );
	}

	public function test_is_complete_true_with_configured_provider() {
		update_user_meta( $this->user_id, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array( 'Two_Factor_Email' ) );
		update_user_meta( $this->user_id, Two_Factor_Core::PROVIDER_USER_META_KEY, 'Two_Factor_Email' );

		$this->assertTrue( Two_Factor_Enrollment::is_complete( $this->user_id ) );
	}

	public function test_email_provider_available_by_default() {
		$this->assertTrue( Two_Factor_Enrollment::email_provider_available() );
	}

	public function test_email_provider_unavailable_when_filtered_out() {
		add_filter(
			'two_factor_providers',
			function ( $providers ) {
				unset( $providers['Two_Factor_Email'] );
				return $providers;
			}
		);

		$this->assertFalse( Two_Factor_Enrollment::email_provider_available() );
	}

	public function test_fetch_user_given_user_object() {
		$user   = get_user_by( 'id', $this->user_id );
		$result = Two_Factor_Enrollment::fetch_user( $user );

		$this->assertInstanceOf( 'WP_User', $result );
		$this->assertSame( $this->user_id, $result->ID );
	}

	public function test_fetch_user_given_non_existent_user_object() {
		$user   = new WP_User( 999999 );
		$result = Two_Factor_Enrollment::fetch_user( $user );

		$this->assertFalse( $result );
	}

	public function test_fetch_user_given_null_resolves_to_current_user() {
		wp_set_current_user( $this->user_id );
		$result = Two_Factor_Enrollment::fetch_user( null );

		$this->assertInstanceOf( 'WP_User', $result );
		$this->assertSame( $this->user_id, $result->ID );
	}

	public function test_fetch_user_given_null_returns_false_without_current_user() {
		wp_set_current_user( 0 );
		$result = Two_Factor_Enrollment::fetch_user( null );

		$this->assertFalse( $result );
	}

	public function test_fetch_user_given_non_numeric_string() {
		$result = Two_Factor_Enrollment::fetch_user( 'abc' );

		$this->assertFalse( $result );
	}

	public function test_is_deferrable_false_for_invalid_user_even_when_filtered() {
		add_filter( 'two_factor_enrollment_deferrable', '__return_true' );
		$this->assertFalse( Two_Factor_Enrollment::is_deferrable( 999999 ) );
	}

	public function test_is_complete_false_for_invalid_user() {
		$this->assertFalse( Two_Factor_Enrollment::is_complete( 999999 ) );
	}

	public function test_is_deferrable_filter_receives_user_object() {
		$seen = null;
		add_filter(
			'two_factor_enrollment_deferrable',
			function ( $deferrable, $user ) use ( &$seen ) {
				$seen = $user;
				return $deferrable;
			},
			10,
			2
		);

		Two_Factor_Enrollment::is_deferrable( $this->user_id );

		$this->assertInstanceOf( 'WP_User', $seen );
		$this->assertSame( $this->user_id, $seen->ID );
	}
}
