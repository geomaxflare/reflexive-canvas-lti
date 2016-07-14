<?php

namespace smtech\ReflexiveCanvasLTI;

use LTI_User;

/**
 * Provide a Canvas-friendly wrapper to the `LTI_User` object
 *
 * @author Seth Battis <SethBattis@stmarksschool.org>
 */
class CanvasUser extends LTI_User {

	/** @var CanvasProperties $canvas Canvas-specific settings */
	public $canvas;

	/**
	 * Construct an object from an `LTI_User`
	 * @param LTI_User $lti_user
	 */
	public function __construct($lti_user) {
		if (is_a($lti_user, LTI_User::class)) {

			/* copy existing properties */
	        foreach (get_object_vars($lti_user) as $key => $value) {
	            $this->$key = $value;
	        }

			$this->canvas = new CanvasSettings($lti_user->getResourceLink()->settings);
		} else {
			throw new CanvasUser_Exception(
				'Expected an instance of `LTI_User`, received `' . get_class($lti_user) . '` instead.',
				CanvasUser_Exception::MISSING_PARAMETER
			);
		}
	}
}