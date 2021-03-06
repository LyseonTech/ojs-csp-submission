<?php

/**
 * @defgroup plugins_generic_cspSubmission cspSubmission Plugin
 */
 
/**
 * @file plugins/generic/cspSubmission/index.php
 *
 * Copyright (c) 2019-2021 LibreCodeCoop
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @ingroup plugins_generic_cspSubmission
 * @brief Wrapper for cspSubmission plugin.
 *
 */
require_once('CspSubmissionPlugin.inc.php');

return new CspSubmissionPlugin();


