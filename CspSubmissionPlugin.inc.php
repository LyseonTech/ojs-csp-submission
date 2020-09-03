<?php

/**
 * @file plugins/generic/CspSubmission/CspSubmissionPlugin.inc.php
 *
 * Copyright (c) 2014-2019 LyseonTech
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class CspSubmissionPlugin
 * @ingroup plugins_generic_CspSubmission
 *
 * @brief CspSubmission plugin class
 */

use APP\Services\QueryBuilders\SubmissionQueryBuilder;
use Illuminate\Database\Capsule\Manager as Capsule;
use Symfony\Component\HttpClient\HttpClient;

import('lib.pkp.classes.plugins.GenericPlugin');
require_once(dirname(__FILE__) . '/vendor/autoload.php');

class CspSubmissionPlugin extends GenericPlugin {
	/**
	 * @copydoc Plugin::register()
	 */
	function register($category, $path, $mainContextId = null) {
		$success = parent::register($category, $path, $mainContextId);
		if ($success) {

			// Insert new field into author metadata submission form (submission step 3) and metadata form
			HookRegistry::register('Templates::Submission::SubmissionMetadataForm::AdditionalMetadata', array($this, 'metadataFieldEdit'));
			HookRegistry::register('TemplateManager::fetch', array($this, 'TemplateManager_fetch'));
			HookRegistry::register('TemplateManager::display',array(&$this, 'templateManager_display'));
			HookRegistry::register('FileManager::downloadFile',array($this, 'fileManager_downloadFile'));
			HookRegistry::register('Mail::send', array($this,'mail_send'));
			HookRegistry::register('submissionfilesuploadform::display', array($this,'submissionfilesuploadform_display'));

			HookRegistry::register('Submission::getMany::queryObject', array($this,'submission_getMany_queryObject'));

			HookRegistry::register('APIHandler::endpoints', array($this,'APIHandler_endpoints'));

			// Hook for initData in two forms -- init the new field
			HookRegistry::register('submissionsubmitstep3form::initdata', array($this, 'metadataInitData'));

			// Hook for readUserVars in two forms -- consider the new field entry
			HookRegistry::register('submissionsubmitstep3form::readuservars', array($this, 'metadataReadUserVars'));

			// Hook for execute in two forms -- consider the new field in the article settings
			HookRegistry::register('submissionsubmitstep3form::execute', array($this, 'metadataExecuteStep3'));
			HookRegistry::register('submissionsubmitstep4form::execute', array($this, 'metadataExecuteStep4'));

			// Hook for save in two forms -- add validation for the new field
			HookRegistry::register('submissionsubmitstep3form::Constructor', array($this, 'addCheck'));

			// Consider the new field for ArticleDAO for storage
			HookRegistry::register('articledao::getAdditionalFieldNames', array($this, 'metadataReadUserVars'));

			HookRegistry::register('submissionfilesuploadform::validate', array($this, 'submissionfilesuploadformValidate'));

			HookRegistry::register('SubmissionHandler::saveSubmit', array($this, 'SubmissionHandler_saveSubmit'));
			HookRegistry::register('User::getMany::queryObject', array($this, 'pkp_services_pkpuserservice_getmany'));
			HookRegistry::register('UserDAO::_returnUserFromRowWithData', array($this, 'userDAO__returnUserFromRowWithData'));
			HookRegistry::register('User::getProperties::values', array($this, 'user_getProperties_values'));
			HookRegistry::register('authorform::initdata', array($this, 'authorform_initdata'));

			// This hook is used to register the components this plugin implements to
			// permit administration of custom block plugins.
			HookRegistry::register('LoadComponentHandler', array($this, 'setupGridHandler'));

			HookRegistry::register('userstageassignmentdao::_filterusersnotassignedtostageinusergroup', array($this, 'userstageassignmentdao_filterusersnotassignedtostageinusergroup'));
			
			HookRegistry::register('Template::Workflow::Publication', array($this, 'workflowFieldEdit'));

			HookRegistry::register('addparticipantform::execute', array($this, 'addparticipantformExecute'));

			HookRegistry::register('Publication::edit', array($this, 'publicationEdit'));
		}
		return $success;
	}

	/**
	 * Hooked to the the `display` callback in TemplateManager
	 * @param $hookName string
	 * @param $args array
	 * @return boolean
	 */
	public function templateManager_display($hookName, $args) {
		if ($args[1] == "submission/form/index.tpl") {

			$request =& Registry::get('request');
			$templateManager =& $args[0];
	
			// // Load JavaScript file
			$templateManager->addJavaScript(
				'coautor',
				$request->getBaseUrl() . DIRECTORY_SEPARATOR . $this->getPluginPath() . '/js/build.js',
				array(
					'contexts' => 'backend',
					'priority' => STYLE_SEQUENCE_LAST,
				)
			);
			$templateManager->addStyleSheet(
				'coautor',
				$request->getBaseUrl() . DIRECTORY_SEPARATOR . $this->getPluginPath() . '/styles/build.css',
				array(
					'contexts' => 'backend',
					'priority' => STYLE_SEQUENCE_LAST,
				)
			);
		} elseif ($args[1] == "dashboard/index.tpl") {
			$templateManager =& $args[0];
			$containerData = $templateManager->get_template_vars('containerData');
			$stages[] = $containerData['components']['myQueue']['filters'][1]['filters'][0];
			$stages[] = [
				'param' => 'substage',
				'value' => 1,
				'title' => '> Aguardando secretaria'
			];
			$stages[] = $containerData['components']['myQueue']['filters'][1]['filters'][1];
			$stages[] = $containerData['components']['myQueue']['filters'][1]['filters'][2];
			$stages[] = $containerData['components']['myQueue']['filters'][1]['filters'][3];
			$containerData['components']['myQueue']['filters'][1]['filters'] = $stages;
			$templateManager->assign('containerData', $containerData);
		}

		return false;
	}

	public function submission_getMany_queryObject($hookName, $args) {
		$request = \Application::get()->getRequest();
		/**
		 * @var SubmissionQueryBuilder
		 */
		$qb = $args[0];
		$request = \Application::get()->getRequest();
		$substage = $request->getUserVar('substage');
		if ($substage) {
			$substage = $substage[0];
		}
		switch ($substage) {
			case 1:
				$qb->where('s.stage_id', '=', 1);
				break;
		}
		$params = $args[1];
	}

	/**
	 * Permit requests to the custom block grid handler
	 * @param $hookName string The name of the hook being invoked
	 * @param $args array The parameters to the invoked hook
	 */
	function setupGridHandler($hookName, $params) {
		$component =& $params[0];
		if ($component == 'plugins.generic.CspSubmission.controllers.grid.AddAuthorHandler') {
			return true;
		}
		return false;
	}

	function addparticipantformExecute($hookName, $args){
		$args[0]->_data["userGroupId"] = 1;
		$request = \Application::get()->getRequest();	
	}
	
	function mail_send($hookName, $args){
		//return;
		$request = \Application::get()->getRequest();
		$stageId = $request->getUserVar('stageId');		
		$decision = $request->getUserVar('decision');		
		$submissionId = $request->getUserVar('submissionId');		

		if($stageId == 3 && $decision == 1){  // AO ACEITAR SUBMISSÃO, OS EDITORES ASSISTENTES DEVEM SER NOTIFICADOS
			$locale = AppLocale::getLocale();
			$userDao = DAORegistry::getDAO('UserDAO');
			$result = $userDao->retrieve(
				<<<QUERY
				SELECT 		a.user_id, u.email, b.setting_value
				FROM 		( 	SELECT s.user_group_id, g.user_id 
								FROM ojs.user_user_groups g
								LEFT JOIN ojs.user_group_settings s
								ON s.user_group_id = g.user_group_id
								LEFT JOIN ojs.stage_assignments a
								ON g.user_id = a.user_id AND a.submission_id = $submissionId
								WHERE s.setting_value = 'Assistente editorial'
							)a
				LEFT JOIN 	ojs.users u
				ON 			u.user_id = a.user_id 
				LEFT JOIN	( 	SELECT user_id, setting_value
								FROM ojs.user_settings
								WHERE setting_name = 'givenName' AND locale = '$locale'
							)b 
				ON b.user_id = a.user_id				
				QUERY
			);

			$args[0]->_data["recipients"] = [];

			while (!$result->EOF) {
				
				$args[0]->_data["recipients"][]= ["name" => $result->GetRowAssoc(0)['setting_value'], "email" => $result->GetRowAssoc(0)['email']];

				$result->MoveNext();																
			}
			
		}


/* 		if (!empty($args[0]->emailKey) && $args[0]->emailKey == "REVIEW_REQUEST_ONECLICK"){			
			$body = $args[0]->_data['body'];
			
			preg_match("/href='(?P<url>.*)' class='submissionReview/",$body,$matches);
			$body = str_replace('{$submissionReviewUrlAccept}', $matches['url']."&accept=yes", $body);
			$body = str_replace('{$submissionReviewUrlReject}', $matches['url']."&accept=no", $body);
			$args[0]->_data['body'] = $body;
		}
		if ($stageId == 3 && !empty($args[0]->emailKey) && $args[0]->emailKey == "NOTIFICATION"){
			return true;
		}if ($args[0]->emailKey == "EDITOR_DECISION_INITIAL_DECLINE"){
			$request = \Application::get()->getRequest();;
			$subject = $request->_requestVars["subject"];
			$locale = AppLocale::getLocale();
			$userDao = DAORegistry::getDAO('UserDAO');
			$result = $userDao->retrieve(
				<<<QUERY
				SELECT a.email_key, a.body, a.subject

				FROM 
				
				(
					SELECT 	d.email_key, d.body, d.subject	
					FROM 	email_templates_default_data d	
					WHERE 	d.locale = '$locale'
					
					UNION ALL 
					
					SELECT 	t.email_key, o.body, o.subject	
					FROM 	ojs.email_templates t
					
					LEFT JOIN
					(
						SELECT 	a.body, b.subject, a.email_id
						FROM
						(
							SELECT 	setting_value as body, email_id
							FROM 	email_templates_settings 
							WHERE 	setting_name = 'body' AND locale = '$locale'
						)a
						LEFT JOIN
						(
								SELECT 	setting_value as subject, email_id
								FROM 	email_templates_settings
								WHERE 	setting_name = 'subject' AND locale = '$locale'
						)b
						ON a.email_id = b.email_id
					) o	
					ON o.email_id = t.email_id
					WHERE t.enabled = 1
				) a
				WHERE 	a.email_key  = '$subject'

				QUERY
			);

			$args[0]->setData('subject', $result->GetRowAssoc(false)['subject']);			
		} */

		if($request->_router->_page == "reviewer"){ // AVALIADOR RECEBE E-MAIL DE AGRADECIMENTO APÓS SUBMETER AVALIAÇÃO
			if($request->_requestVars["step"] == 1){
				return true;
			}
			if($request->_requestVars["step"] == 3){

				$locale = AppLocale::getLocale();
				$userDao = DAORegistry::getDAO('UserDAO');
				$result = $userDao->retrieve(
					<<<QUERY
					SELECT a.email_key, a.body, a.subject

					FROM

					(
						SELECT 	d.email_key, d.body, d.subject
						FROM 	email_templates_default_data d
						WHERE 	d.locale = '$locale'

						UNION ALL

						SELECT 	t.email_key, o.body, o.subject
						FROM 	ojs.email_templates t

						LEFT JOIN
						(
							SELECT 	a.body, b.subject, a.email_id
							FROM
							(
								SELECT 	setting_value as body, email_id
								FROM 	email_templates_settings
								WHERE 	setting_name = 'body' AND locale = '$locale'
							)a
							LEFT JOIN
							(
									SELECT 	setting_value as subject, email_id
									FROM 	email_templates_settings
									WHERE 	setting_name = 'subject' AND locale = '$locale'
							)b
							ON a.email_id = b.email_id
						) o
						ON o.email_id = t.email_id
						WHERE t.enabled = 1
					) a
					WHERE 	a.email_key  = 'REVIEW_THANK'

					QUERY
				);
				/// O EMAIL ESTÁ SENDO EVIADO DIVERSAR VEZES PARA O AVALIADO - RESOLVER !!!!
				$args[0]->_data['body'] = $result->GetRowAssoc(false)['body'];
				$args[0]->_data['subject'] = $result->GetRowAssoc(false)['subject'];
				$args[0]->_data["from"]["name"] = "CSP";
				$args[0]->_data["from"]["email"] = "noreply@lt.coop.br";
				$args[0]->_data["recipients"][0]["name"] = $args[0]->params["senderName"];
				$args[0]->_data["recipients"][0]["email"] = $args[0]->params["senderEmail"];
			}

		}
		if($args[0]->emailKey == "REVISED_VERSION_NOTIFY"){ // QUANDO AUTOR SUBMETE TEXTO REVISADO, EMAIL VAI PARA SECRETARIA

			unset($args[0]->_data["recipients"]);

			$locale = AppLocale::getLocale();

			$userDao = DAORegistry::getDAO('UserDAO');
			$result = $userDao->retrieve(
				<<<QUERY
				SELECT u.email, x.setting_value as name
				FROM ojs.stage_assignments a
				LEFT JOIN ojs.users u
				ON a.user_id = u.user_id
				LEFT JOIN (SELECT user_id, setting_value FROM ojs.user_settings WHERE setting_name = 'givenName' AND locale = '$locale') x
				ON x.user_id = u.user_id
				WHERE submission_id = $submissionId AND user_group_id = 23
				QUERY
			);

			while (!$result->EOF) {
				$args[0]->_data["recipients"][] =  array("name" => $result->GetRowAssoc(0)['name'], "email" => $result->GetRowAssoc(0)['email']);
				//$templateSubject[$result->GetRowAssoc(0)['email_key']] = $result->GetRowAssoc(0)['subject'];

				$result->MoveNext();
			}
		}
	}

	public function APIHandler_endpoints($hookName, $args) {
		if (isset($args[0]['GET'])) {
			foreach($args[0]['GET'] as $key => $endpoint) {
				if ($endpoint['pattern'] == '/{contextPath}/api/{version}/users') {
					if (!in_array(ROLE_ID_AUTHOR, $endpoint['roles'])) {
						$args[0]['GET'][$key]['roles'][] = ROLE_ID_AUTHOR;
					}
				}
			}
		}
	}

	function TemplateManager_fetch($hookName, $args) {
		$args[1];
		$templateMgr =& $args[0];
		$request = \Application::get()->getRequest();
		$stageId = $request->getUserVar('stageId');
		$submissionId = $request->getUserVar('submissionId');
		//$itemId = $request->getUserVar('istemId');

		if ($args[1] == 'submission/form/step1.tpl') {
			//$args[4] = $templateMgr->fetch($this->getTemplateResource('step1.tpl'));
			
			//return true;
		} elseif ($args[1] == 'submission/form/step3.tpl'){
			$args[4] = $templateMgr->fetch($this->getTemplateResource('step3.tpl'));
			
			return true;

		} elseif ($args[1] == 'controllers/grid/gridCell.tpl'){
			$args[4] = $templateMgr->fetch($this->getTemplateResource('gridCell.tpl'));

			return true;
		} elseif ($args[1] == 'controllers/wizard/fileUpload/form/fileUploadConfirmationForm.tpl'){
			$args[4] = $templateMgr->fetch($this->getTemplateResource('fileUploadConfirmationForm.tpl'));

			return true;
		} elseif ($args[1] == 'controllers/wizard/fileUpload/form/submissionArtworkFileMetadataForm.tpl') {
			$args[4] = $templateMgr->fetch($this->getTemplateResource('submissionArtworkFileMetadataForm.tpl'));
			
			return true;
		} elseif ($args[1] == 'controllers/grid/users/author/form/authorForm.tpl') {
			$request = \Application::get()->getRequest();
			$operation = $request->getRouter()->getRequestedOp($request);
			switch ($operation) {
				case 'addAuthor':
					if ($request->getUserVar('userId')) {
						$args[4] = $templateMgr->fetch($this->getTemplateResource('authorFormAdd.tpl'));
						return true;
					}
					import('plugins.generic.cspSubmission.controllers.list.autor.CoautorListPanel');

					$coautorListHandler = new CoautorListPanel(
						'CoautorListPanel',
						__('plugins.generic.CspSubmission.searchForAuthor'),
						[
							'apiUrl' => $request->getDispatcher()->url($request, ROUTE_API, $request->getContext()->getPath(), 'users'),
							'getParams' => [
								'roleIds' => [ROLE_ID_AUTHOR],
								'orderBy' => 'givenName',
								'orderDirection' => 'ASC'
							]
						]
					);

					$templateMgr->assign('containerData', ['components' => ['CoautorListPanel' => $coautorListHandler->getConfig()]]);
					$templateMgr->assign('basejs', $request->getBaseUrl() . DIRECTORY_SEPARATOR . $this->getPluginPath() . '/js/build.js');
					$templateMgr->assign('basecss', $request->getBaseUrl() . DIRECTORY_SEPARATOR . $this->getPluginPath() . '/styles/build.css');

					$args[4] = $templateMgr->fetch($this->getTemplateResource('authorForm.tpl'));
					return true;
				case 'updateAuthor':
					$templateMgr->assign('csrfToken', $request->getSession()->getCSRFToken());
					$args[4] = $templateMgr->fetch($this->getTemplateResource('authorFormAdd.tpl'));
					return true;
			}
		} elseif ($args[1] == 'controllers/modals/submissionMetadata/form/issueEntrySubmissionReviewForm.tpl') {
			$args[4] = $templateMgr->fetch($this->getTemplateResource('issueEntrySubmissionReviewForm.tpl'));

			return true;
		} elseif ($args[1] == 'controllers/grid/users/reviewer/form/advancedSearchReviewerForm.tpl') {
			
			$request = \Application::get()->getRequest();
			$submissionDAO = Application::getSubmissionDAO();
			$submission = $submissionDAO->getById($request->getUserVar('submissionId'));
			$templateMgr->assign('title',$submission->getTitle(AppLocale::getLocale()));
			$args[4] = $templateMgr->fetch($this->getTemplateResource('advancedSearchReviewerForm.tpl'));

			return true;
		}elseif ($args[1] == 'reviewer/review/step1.tpl') {
			$args[4] = $templateMgr->fetch($this->getTemplateResource('reviewStep1.tpl'));
			
			return true;
		}elseif ($args[1] == 'reviewer/review/step3.tpl') {
			$templateMgr->assign(array(
				'reviewerRecommendationOptions' =>	array(
															'' => 'common.chooseOne',
															SUBMISSION_REVIEWER_RECOMMENDATION_ACCEPT => 'reviewer.article.decision.accept',
															SUBMISSION_REVIEWER_RECOMMENDATION_PENDING_REVISIONS => 'reviewer.article.decision.pendingRevisions',
															SUBMISSION_REVIEWER_RECOMMENDATION_DECLINE => 'reviewer.article.decision.decline',
														)
			));

			$args[4] = $templateMgr->fetch($this->getTemplateResource('reviewStep3.tpl'));
			return true;
		}elseif ($args[1] == 'reviewer/review/reviewCompleted.tpl') {
			$args[4] = $templateMgr->fetch($this->getTemplateResource('reviewCompleted.tpl'));

			return true;
		}elseif ($args[1] == 'controllers/grid/users/stageParticipant/addParticipantForm.tpl') {
			if($stageId == 5){
				$locale = AppLocale::getLocale();
				$userDao = DAORegistry::getDAO('UserDAO');
				$result = $userDao->retrieve(
					<<<QUERY
					SELECT t.email_key, o.body, o.subject
					FROM email_templates t
					LEFT JOIN
					(
						SELECT a.body, b.subject, a.email_id
						FROM
						(
							SELECT setting_value as body, email_id
							FROM ojs.email_templates_settings 
							WHERE setting_name = 'body' AND locale = '$locale'
						)a
						LEFT JOIN
						(
								SELECT setting_value as subject, email_id
								FROM ojs.email_templates_settings
								WHERE setting_name = 'subject' AND locale = '$locale'
						)b
						ON a.email_id = b.email_id
					) o	
					ON o.email_id = t.email_id
					WHERE t.enabled = 1 AND t.email_key LIKE 'LAYOUT%'
					QUERY
				);
				$i = 0;
				while (!$result->EOF) {
					$i++;
					$templateSubject[$result->GetRowAssoc(0)['email_key']] = $result->GetRowAssoc(0)['subject'];
					$templateBody[$result->GetRowAssoc(0)['email_key']] = $result->GetRowAssoc(0)['body'];
	
					$result->MoveNext();
				}
	
				$templateMgr = TemplateManager::getManager($request);
				$templateMgr->assign(array(
					'templates' => $templateSubject,
					//'stageId' => $stageId,
					//'submissionId' => $this->_submissionId,
					//'itemId' => $this->_itemId,
					'message' => json_encode($templateBody),
					'comment' => reset($templateBody)
				));

				$args[4] = $templateMgr->fetch($this->getTemplateResource('addParticipantForm.tpl'));

				return true;				
			}elseif($stageId == 4){
				$locale = AppLocale::getLocale();
				$userDao = DAORegistry::getDAO('UserDAO');
				$result = $userDao->retrieve(
					<<<QUERY
					SELECT t.email_key, o.body, o.subject
					FROM email_templates t
					LEFT JOIN
					(
						SELECT a.body, b.subject, a.email_id
						FROM
						(
							SELECT setting_value as body, email_id
							FROM ojs.email_templates_settings 
							WHERE setting_name = 'body' AND locale = '$locale'
						)a
						LEFT JOIN
						(
								SELECT setting_value as subject, email_id
								FROM ojs.email_templates_settings
								WHERE setting_name = 'subject' AND locale = '$locale'
						)b
						ON a.email_id = b.email_id
					) o	
					ON o.email_id = t.email_id
					WHERE t.enabled = 1 AND t.email_key LIKE 'COPYEDIT%'
					QUERY
				);
				$i = 0;
				while (!$result->EOF) {
					$i++;
					$templateSubject[$result->GetRowAssoc(0)['email_key']] = $result->GetRowAssoc(0)['subject'];
					$templateBody[$result->GetRowAssoc(0)['email_key']] = $result->GetRowAssoc(0)['body'];
	
					$result->MoveNext();
				}
	
				$templateMgr = TemplateManager::getManager($request);
				$templateMgr->assign(array(
					'templates' => $templateSubject,
					//'stageId' => $stageId,
					//'submissionId' => $this->_submissionId,
					//'itemId' => $this->_itemId,
					'message' => json_encode($templateBody),
					'comment' => reset($templateBody)
				));

				$args[4] = $templateMgr->fetch($this->getTemplateResource('addParticipantForm.tpl'));

				return true;				

			}elseif($stageId == 3 OR $stageId == 1){
				$locale = AppLocale::getLocale();
				$userDao = DAORegistry::getDAO('UserDAO');
				$result = $userDao->retrieve(
					<<<QUERY
					SELECT t.email_key, o.body, o.subject
					FROM email_templates t
					LEFT JOIN
					(
						SELECT a.body, b.subject, a.email_id
						FROM
						(
							SELECT setting_value as body, email_id
							FROM ojs.email_templates_settings 
							WHERE setting_name = 'body' AND locale = '$locale'
						)a
						LEFT JOIN
						(
								SELECT setting_value as subject, email_id
								FROM ojs.email_templates_settings
								WHERE setting_name = 'subject' AND locale = '$locale'
						)b
						ON a.email_id = b.email_id
					) o	
					ON o.email_id = t.email_id
					WHERE t.enabled = 1 AND t.email_key = 'EDITOR_ASSIGN'
					QUERY
				);
				$i = 0;
				while (!$result->EOF) {
					$i++;
					$templateSubject[$result->GetRowAssoc(0)['email_key']] = $result->GetRowAssoc(0)['subject'];
					$templateBody[$result->GetRowAssoc(0)['email_key']] = $result->GetRowAssoc(0)['body'];
	
					$result->MoveNext();
				}
	
				$templateMgr = TemplateManager::getManager($request);
				$templateMgr->assign(array(
					'templates' => $templateSubject,
					//'stageId' => $stageId,
					//'submissionId' => $this->_submissionId,
					//'itemId' => $this->_itemId,
					'message' => json_encode($templateBody),
					'comment' => reset($templateBody)
				));

				$args[4] = $templateMgr->fetch($this->getTemplateResource('addParticipantForm.tpl'));

				return true;				

			}

		}elseif ($args[1] == 'controllers/modals/editorDecision/form/promoteForm.tpl') {
			$decision = $request->_requestVars["decision"];
			if ($stageId == 3 or $stageId == 1){
				if($decision == 1){
					
						$request = \Application::get()->getRequest();		
						$submissionId = $request->getUserVar('submissionId');

						$userDao = DAORegistry::getDAO('UserDAO');
						$result = $userDao->retrieve(
							<<<QUERY
							SELECT s.user_group_id , g.user_id, a.user_id as assigned 
							FROM ojs.user_user_groups g
							LEFT JOIN ojs.user_group_settings s
							ON s.user_group_id = g.user_group_id
							LEFT JOIN ojs.stage_assignments a
							ON g.user_id = a.user_id AND a.submission_id = $submissionId
							WHERE s.setting_value = 'Assistente editorial'
							QUERY
						);
						while (!$result->EOF) {

							if($result->GetRowAssoc(0)['assigned'] == NULL){

								$userGroupId = $result->GetRowAssoc(0)['user_group_id'];
								$userId = $result->GetRowAssoc(0)['user_id'];
									
								$stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO'); /* @var $stageAssignmentDao StageAssignmentDAO */
								$stageAssignment = $stageAssignmentDao->newDataObject();
								$stageAssignment->setSubmissionId($submissionId);
								$stageAssignment->setUserGroupId($userGroupId);
								$stageAssignment->setUserId($userId);
								$stageAssignment->setRecommendOnly(1);
								$stageAssignment->setCanChangeMetadata(1);
								$stageAssignmentDao->insertObject($stageAssignment);
		
								$submissionDAO = Application::getSubmissionDAO();
								$submission = $submissionDAO->getById($submissionId);
		
								$userDao = DAORegistry::getDAO('UserDAO'); /* @var $userDao UserDAO */
								$assignedUser = $userDao->getById($userId);
								$userGroupDao = DAORegistry::getDAO('UserGroupDAO'); /* @var $userGroupDao UserGroupDAO */
								$userGroup = $userGroupDao->getById($userGroupId);

								import('lib.pkp.classes.log.SubmissionLog');
								SubmissionLog::logEvent($request, $submission, SUBMISSION_LOG_ADD_PARTICIPANT, 'submission.event.participantAdded', array('name' => $assignedUser->getFullName(), 'username' => $assignedUser->getUsername(), 'userGroupName' => $userGroup->getLocalizedName()));

							}

							$result->MoveNext();
						}

				}
				$templateMgr->assign('skipEmail',1); // PASSA VARIÁVEL PARA NÃO ENVIAR EMAIL PARA O AUTOR

				$args[4] = $templateMgr->fetch($this->getTemplateResource('promoteFormStage1And3.tpl'));

				return true;

			}elseif ($stageId == 4){
//				$args[4] = $templateMgr->fetch($this->getTemplateResource('promoteFormStage4.tpl'));

//				return true;
			}
		}elseif ($args[1] == 'controllers/modals/editorDecision/form/sendReviewsForm.tpl') {
			
			$decision = $request->_requestVars["decision"];

			if ($decision == 2){ // BOTÃO SOLICITAR MODIFICAÇÕES
				/* 
				
				$templateMgr->assign('skipEmail',0); // PASSA VARIÁVEL PARA ENVIAR EMAIL PARA O AUTOR
				$templateMgr->assign('decision',3); // PASSA VARIÁVEL PARA SELECIONAR O CAMPO " Solicitar modificações ao autor que estarão sujeitos a avaliação futura."

				$locale = AppLocale::getLocale();
				$userDao = DAORegistry::getDAO('UserDAO');
				$result = $userDao->retrieve(
					<<<QUERY

					SELECT a.email_key, a.body, a.subject

					FROM 
					
					(
						SELECT 	d.email_key, d.body, d.subject	
						FROM 	email_templates_default_data d	
						WHERE 	d.locale = '$locale'
						
						UNION ALL 
						
						SELECT 	t.email_key, o.body, o.subject	
						FROM 	ojs.email_templates t
						
						LEFT JOIN
						(
							SELECT 	a.body, b.subject, a.email_id
							FROM
							(
								SELECT 	setting_value as body, email_id
								FROM 	email_templates_settings 
								WHERE 	setting_name = 'body' AND locale = '$locale'
							)a
							LEFT JOIN
							(
									SELECT 	setting_value as subject, email_id
									FROM 	email_templates_settings
									WHERE 	setting_name = 'subject' AND locale = '$locale'
							)b
							ON a.email_id = b.email_id
						) o	
						ON o.email_id = t.email_id
						WHERE t.enabled = 1
					) a
					WHERE 	a.email_key LIKE 'REQUEST_REVISIONS%'					
					
					QUERY
				);
				$i = 0;
				while (!$result->EOF) {
					$i++;
					$templateSubject[$result->GetRowAssoc(0)['email_key']] = $result->GetRowAssoc(0)['subject'];
					$templateBody[$result->GetRowAssoc(0)['email_key']] = $result->GetRowAssoc(0)['body'];
	
					$result->MoveNext();
				}

				$templateMgr = TemplateManager::getManager($request);
				$templateMgr->assign(array(
					'templates' => $templateSubject,
					'stageId' => $stageId,
					'message' => json_encode($templateBody),
					'default' => reset($templateBody)
				));						 */

			}elseif ($decision == 4 or $decision == 9){  // BOTÃO REJEITAR SUBMISSÃO
				return;
				$locale = AppLocale::getLocale();
				$userDao = DAORegistry::getDAO('UserDAO');
				$result = $userDao->retrieve(
					<<<QUERY

					SELECT a.email_key, a.body, a.subject

					FROM 
					
					(
						SELECT 	d.email_key, d.body, d.subject	
						FROM 	email_templates_default_data d	
						WHERE 	d.locale = '$locale'
						
						UNION ALL 
						
						SELECT 	t.email_key, o.body, o.subject	
						FROM 	ojs.email_templates t
						
						LEFT JOIN
						(
							SELECT 	a.body, b.subject, a.email_id
							FROM
							(
								SELECT 	setting_value as body, email_id
								FROM 	email_templates_settings 
								WHERE 	setting_name = 'body' AND locale = '$locale'
							)a
							LEFT JOIN
							(
									SELECT 	setting_value as subject, email_id
									FROM 	email_templates_settings
									WHERE 	setting_name = 'subject' AND locale = '$locale'
							)b
							ON a.email_id = b.email_id
						) o	
						ON o.email_id = t.email_id
						WHERE t.enabled = 1
					) a
					WHERE 	a.email_key LIKE 'EDITOR_DECISION_INITIAL_DECLINE%'					
					
					QUERY
				);
				$i = 0;
				while (!$result->EOF) {
					$i++;
					$templateSubject[$result->GetRowAssoc(0)['email_key']] = $result->GetRowAssoc(0)['subject'];
					$templateBody[$result->GetRowAssoc(0)['email_key']] = $result->GetRowAssoc(0)['body'];
	
					$result->MoveNext();
				}

				$templateMgr = TemplateManager::getManager($request);
				$templateMgr->assign(array(
					'templates' => $templateSubject,
					'stageId' => $stageId,
					'message' => json_encode($templateBody),
					'default' => reset($templateBody)
				));				
			}

			$args[4] = $templateMgr->fetch($this->getTemplateResource('sendReviewsForm.tpl'));

			return true;

		}elseif ($args[1] == 'controllers/grid/queries/form/queryForm.tpl' && $stageId == "1") {
			$locale = AppLocale::getLocale();
			$userDao = DAORegistry::getDAO('UserDAO');
			$result = $userDao->retrieve(
				<<<QUERY
				SELECT t.email_key, o.body, o.subject
				FROM email_templates t
				LEFT JOIN
				(
					SELECT a.body, b.subject, a.email_id
					FROM
					(
						SELECT setting_value as body, email_id
						FROM ojs.email_templates_settings 
						WHERE setting_name = 'body' AND locale = '$locale'
					)a
					LEFT JOIN
					(
							SELECT setting_value as subject, email_id
							FROM ojs.email_templates_settings
							WHERE setting_name = 'subject' AND locale = '$locale'
					)b
					ON a.email_id = b.email_id
				) o	
				ON o.email_id = t.email_id
				WHERE t.enabled = 1 AND t.email_key LIKE 'PRE_AVALIACAO%'
				QUERY
			);
			$i = 0;
			while (!$result->EOF) {
				$i++;
				$templateSubject[$result->GetRowAssoc(0)['email_key']] = $result->GetRowAssoc(0)['subject'];
				$templateBody[$result->GetRowAssoc(0)['email_key']] = $result->GetRowAssoc(0)['body'];

				$result->MoveNext();
			}

			$templateMgr = TemplateManager::getManager($request);
			$templateMgr->assign(array(
				'templates' => $templateSubject,
				'stageId' => $stageId,
				'submissionId' => $this->_submissionId,
				'itemId' => $this->_itemId,
				'message' => json_encode($templateBody),
				'comment' => reset($templateBody)
			));

			$args[4] = $templateMgr->fetch($this->getTemplateResource('queryForm.tpl'));

			return true;

		}elseif ($args[1] == 'controllers/grid/queries/form/queryForm.tpl' && $stageId == "4") {
			$locale = AppLocale::getLocale();
			$userDao = DAORegistry::getDAO('UserDAO');
			$userId = $_SESSION["userId"];
			$result = $userDao->retrieve( // VERIFICA SE O PERFIL É AUTOR
				<<<QUERY
				SELECT g.user_group_id , g.user_id
				FROM ojs.user_user_groups g
				WHERE g.user_group_id = 14 AND user_id = $userId
				QUERY
			);

			if($result->_numOfRows == 0){

				$result = $userDao->retrieve(
					<<<QUERY
					SELECT t.email_key, o.body, o.subject
					FROM email_templates t
					LEFT JOIN
					(
						SELECT a.body, b.subject, a.email_id
						FROM
						(
							SELECT setting_value as body, email_id
							FROM ojs.email_templates_settings
							WHERE setting_name = 'body' AND locale = '$locale'
						)a
						LEFT JOIN
						(
								SELECT setting_value as subject, email_id
								FROM ojs.email_templates_settings
								WHERE setting_name = 'subject' AND locale = '$locale'
						)b
						ON a.email_id = b.email_id
					) o
					ON o.email_id = t.email_id
					WHERE t.enabled = 1 AND t.email_key LIKE 'EDICAO_TEXTO%'
					QUERY
				);
			}else{
				$result = $userDao->retrieve(
					<<<QUERY
					SELECT t.email_key, o.body, o.subject
					FROM email_templates t
					LEFT JOIN
					(
						SELECT a.body, b.subject, a.email_id
						FROM
						(
							SELECT setting_value as body, email_id
							FROM ojs.email_templates_settings
							WHERE setting_name = 'body' AND locale = '$locale'
						)a
						LEFT JOIN
						(
								SELECT setting_value as subject, email_id
								FROM ojs.email_templates_settings
								WHERE setting_name = 'subject' AND locale = '$locale'
						)b
						ON a.email_id = b.email_id
					) o
					ON o.email_id = t.email_id
					WHERE t.enabled = 1 AND t.email_key LIKE 'EDICAO_TEXTO_MSG_AUTOR%'
					QUERY
				);
			}
			$i = 0;
			while (!$result->EOF) {
				$i++;
				$templateSubject[$result->GetRowAssoc(0)['email_key']] = $result->GetRowAssoc(0)['subject'];
				$templateBody[$result->GetRowAssoc(0)['email_key']] = $result->GetRowAssoc(0)['body'];

				$result->MoveNext();
			}

			$templateMgr = TemplateManager::getManager($request);
			$templateMgr->assign(array(
				'templates' => $templateSubject,
				'stageId' => $stageId,
				'submissionId' => $this->_submissionId,
				'itemId' => $this->_itemId,
				'message' => json_encode($templateBody),
				'comment' => reset($templateBody)
			));

			$args[4] = $templateMgr->fetch($this->getTemplateResource('queryForm.tpl'));

			return true;			
		}elseif ($args[1] == 'controllers/grid/queries/form/queryForm.tpl' && $stageId == "5") {
			$locale = AppLocale::getLocale();
			$userDao = DAORegistry::getDAO('UserDAO');
			$result = $userDao->retrieve(
				<<<QUERY
				SELECT t.email_key, o.body, o.subject
				FROM email_templates t
				LEFT JOIN
				(
					SELECT a.body, b.subject, a.email_id
					FROM
					(
						SELECT setting_value as body, email_id
						FROM ojs.email_templates_settings 
						WHERE setting_name = 'body' AND locale = '$locale'
					)a
					LEFT JOIN
					(
							SELECT setting_value as subject, email_id
							FROM ojs.email_templates_settings
							WHERE setting_name = 'subject' AND locale = '$locale'
					)b
					ON a.email_id = b.email_id
				) o	
				ON o.email_id = t.email_id
				WHERE t.enabled = 1 AND t.email_key LIKE 'EDITORACAO%'
				QUERY
			);
			$i = 0;
			while (!$result->EOF) {
				$i++;
				$templateSubject[$result->GetRowAssoc(0)['email_key']] = $result->GetRowAssoc(0)['subject'];
				$templateBody[$result->GetRowAssoc(0)['email_key']] = $result->GetRowAssoc(0)['body'];

				$result->MoveNext();
			}
			$templateMgr = TemplateManager::getManager($request);
			$templateMgr->assign(array(
				'templates' => $templateSubject,
				'stageId' => $stageId,
				'submissionId' => $submissionId,
				//'itemId' => $itemId,
				'message' => json_encode($templateBody),
				'comment' => reset($templateBody)
			));

			$args[4] = $templateMgr->fetch($this->getTemplateResource('queryForm.tpl'));

			return true;			

		}elseif ($args[1] == 'controllers/wizard/fileUpload/form/fileUploadForm.tpl') {

			$args[4] = $templateMgr->fetch($this->getTemplateResource('fileUploadForm.tpl'));

			return true;

		} elseif ($args[1] == 'controllers/wizard/fileUpload/form/submissionFileMetadataForm.tpl'){
			$tplvars = $templateMgr->getFBV();
			$locale = AppLocale::getLocale();

			$genreId = $tplvars->_form->_submissionFile->_data["genreId"];			
			if($genreId == 47){ // SEM PRE-DEFINIÇÃO DE GÊNERO

				$tplvars->_form->_submissionFile->_data["name"][$locale] = "csp_".$request->_requestVars["submissionId"]."_".date("Y")."_".$tplvars->_form->_submissionFile->_data["originalFileName"];

			}else{
	
				$userDao = DAORegistry::getDAO('UserDAO');
				$result = $userDao->retrieve(
					<<<QUERY
					SELECT setting_value
					FROM ojs.genre_settings
					WHERE genre_id = $genreId AND locale = '$locale'
					QUERY
				);
				$genreName = $result->GetRowAssoc(false)['setting_value'];
				$genreName = str_replace(" ","_",$genreName);
				
				$extensao = pathinfo($tplvars->_form->_submissionFile->_data["originalFileName"], PATHINFO_EXTENSION);
			
				$tplvars->_form->_submissionFile->_data["name"][$locale] = "csp_".$request->_requestVars["submissionId"]."_".date("Y")."_".$genreName.".".$extensao;

			}

		} elseif ($args[1] == 'controllers/grid/users/reviewer/readReview.tpl'){
			$args[4] = $templateMgr->fetch($this->getTemplateResource('readReview.tpl'));

			return true;

		} elseif ($args[1] == 'controllers/modals/editorDecision/form/recommendationForm.tpl'){
			$templateMgr = TemplateManager::getManager($request);
			$templateMgr->assign(array(
				'skipEmail' => true,
				'recommendationOptions' =>	array(
												'' => 'common.chooseOne',
												SUBMISSION_EDITOR_RECOMMEND_PENDING_REVISIONS => 'editor.submission.decision.requestRevisions',
												SUBMISSION_EDITOR_RECOMMEND_ACCEPT => 'editor.submission.decision.accept',
												SUBMISSION_EDITOR_RECOMMEND_DECLINE => 'editor.submission.decision.decline',
											)
			));

			$args[4] = $templateMgr->fetch($this->getTemplateResource('recommendationForm.tpl'));
			return true;

		} elseif ($args[1] == 'controllers/grid/gridRow.tpl' && $request->_requestPath == '/ojs/index.php/csp/$$$call$$$/grid/users/user-select/user-select-grid/fetch-grid'){
			$templateMgr = TemplateManager::getManager($request);
			$columns = $templateMgr->getVariable('columns');
			$cells = $templateMgr->getVariable('cells');
			$row = $templateMgr->getVariable('row');
			$cells->value[] = $row->value->_data->_data["assigns"];
			$columns->value['assigns'] = clone $columns->value["name"];
			$columns->value["assigns"]->_title = "author.users.contributor.assign";

		}


		

		return false;
	}

	public function submissionfilesuploadform_display($hookName, $args)
	{
		/** @var Request */
		$request = \Application::get()->getRequest();
		$fileStage = $request->getUserVar('fileStage');
		$submissionDAO = Application::getSubmissionDAO();
		$submission = $submissionDAO->getById($request->getUserVar('submissionId'));
		$submissionProgress = $submission->getData('submissionProgress');
		$stageId = $request->getUserVar('stageId');		
		$userId = $_SESSION["userId"];			
		$locale = AppLocale::getLocale();	
		$userDao = DAORegistry::getDAO('UserDAO');	

		$templateMgr =& $args[0];

		if ($fileStage == 2 && $submissionProgress == 0){			

/* 			$templateMgr->setData('revisionOnly',false);
			$templateMgr->setData('isReviewAttachment',true);
			$templateMgr->setData('submissionFileOptions',[]);
 */
			//$templateMgr->setData('isReviewAttachment', TRUE); // SETA A VARIÁVEL PARA TRUE POIS ELA É VERIFICADA NO TEMPLATE PARA NÃO EXIBIR OS COMPONENTES
			
			$result = $userDao->retrieve(
				<<<QUERY
				SELECT A.genre_id, setting_value
				FROM ojs.genre_settings A
				LEFT JOIN ojs.genres B
				ON B.genre_id = A.genre_id
				WHERE locale = '$locale' AND entry_key = 'SUBMISSAO_PDF'							
				QUERY
			);
			while (!$result->EOF) {
				$genreList[$result->GetRowAssoc(0)['genre_id']] = $result->GetRowAssoc(0)['setting_value'];

				$result->MoveNext();
			}
			
			$templateMgr->setData('submissionFileGenres', $genreList);	
			$templateMgr->setData('isReviewAttachment', false); // SETA A VARIÁVEL PARA FALSE POIS ELA É VERIFICADA NO TEMPLATE PARA EXIBIR OS COMPONENTES			
		}	

		if ($fileStage == 4) { // SECRETARIA FAZENDO UPLOAD DE NOVA VERSÃO
			
			$result = $userDao->retrieve(
				<<<QUERY
				SELECT A.genre_id, setting_value
				FROM ojs.genre_settings A
				LEFT JOIN ojs.genres B
				ON B.genre_id = A.genre_id
				WHERE locale = '$locale' AND entry_key LIKE 'AVAL_SECRETARIA%'							
				QUERY
			);
			while (!$result->EOF) {
				$genreList[$result->GetRowAssoc(0)['genre_id']] = $result->GetRowAssoc(0)['setting_value'];

				$result->MoveNext();
			}
			
			$templateMgr->setData('submissionFileGenres', $genreList);	
			$templateMgr->setData('isReviewAttachment', false); // SETA A VARIÁVEL PARA FALSE POIS ELA É VERIFICADA NO TEMPLATE PARA EXIBIR OS COMPONENTES

		}

		if ($fileStage == 5) { // AVALIADOR FAZENDO UPLOAD DE PARECER

			$result = $userDao->retrieve(
				<<<QUERY
				SELECT A.genre_id, setting_value
				FROM ojs.genre_settings A
				LEFT JOIN ojs.genres B
				ON B.genre_id = A.genre_id
				WHERE locale = '$locale' AND entry_key LIKE 'AVAL_AVALIADOR%'							
				QUERY
			);
			while (!$result->EOF) {
				$genreList[$result->GetRowAssoc(0)['genre_id']] = $result->GetRowAssoc(0)['setting_value'];

				$result->MoveNext();
			}
			
			$templateMgr->setData('submissionFileGenres', $genreList);	
			$templateMgr->setData('isReviewAttachment', false); // SETA A VARIÁVEL PARA FALSE POIS ELA É VERIFICADA NO TEMPLATE PARA EXIBIR OS COMPONENTES

		}
		if ($fileStage == 6) { // AVALIADOR FAZENDO UPLOAD DE PARECER

			$templateMgr->setData('isReviewAttachment', TRUE); // SETA A VARIÁVEL PARA TRUE POIS ELA É VERIFICADA NO TEMPLATE PARA NÃO EXIBIR OS COMPONENTES

		}		
		if ($fileStage == 9) { // UPLOAD DE ARQUIVO EM BOX DE ARQUIVOS DE REVISÃO DE TEXTO

			$result = $userDao->retrieve( // VERIFICA SE O PERFIL É DE REVISOR/TRADUTOR
				<<<QUERY
				SELECT g.user_group_id , g.user_id 
				FROM ojs.user_user_groups g
				WHERE g.user_group_id = 7 AND user_id = $userId
				QUERY
			);			

			if($result->_numOfRows == 0){
				$result = $userDao->retrieve(
					<<<QUERY
					SELECT A.genre_id, setting_value
					FROM ojs.genre_settings A
					LEFT JOIN ojs.genres B
					ON B.genre_id = A.genre_id
					WHERE locale = '$locale' AND entry_key LIKE 'EDICAO_ASSIST_ED%'							
					QUERY
				);				
			}else{
				$result = $userDao->retrieve(
					<<<QUERY
					SELECT A.genre_id, setting_value
					FROM ojs.genre_settings A
					LEFT JOIN ojs.genres B
					ON B.genre_id = A.genre_id
					WHERE locale = '$locale' AND entry_key LIKE 'EDICAO_TRADUT%'							
					QUERY
				);
			}

			while (!$result->EOF) {
				$genreList[$result->GetRowAssoc(0)['genre_id']] = $result->GetRowAssoc(0)['setting_value'];

				$result->MoveNext();
			}
			
			$templateMgr->setData('submissionFileGenres', $genreList);			

		}		

		if ($fileStage == 11) { // UPLOAD DE ARQUIVO EM BOX DE ARQUIVOS PRONTOS PARA LAYOUT

			$result = $userDao->retrieve( // VERIFICA SE O PERFIL É DE DIAGRAMADOR
				<<<QUERY
				SELECT g.user_group_id 
				FROM ojs.user_user_groups g
				WHERE user_id = $userId
				QUERY
			);			

			while (!$result->EOF) {
				if($result->GetRowAssoc(0)['user_group_id'] == 24){ // ASSISTENTE EDITORIAL
					$result_genre = $userDao->retrieve(
						<<<QUERY
						SELECT A.genre_id, setting_value
						FROM ojs.genre_settings A
						LEFT JOIN ojs.genres B
						ON B.genre_id = A.genre_id
						WHERE locale = '$locale' AND entry_key LIKE 'EDITORACAO_ASSIS_ED_TEMPLT%'							
						QUERY
					);	
				break;
				}elseif($result->GetRowAssoc(0)['user_group_id'] == 22){ // DIAGRAMADOR
					$result_genre = $userDao->retrieve(
						<<<QUERY
						SELECT A.genre_id, setting_value
						FROM ojs.genre_settings A
						LEFT JOIN ojs.genres B
						ON B.genre_id = A.genre_id
						WHERE locale = '$locale' AND entry_key LIKE 'EDITORACAO_DIAGRM%'							
						QUERY
					);
				break;
				}

				$result->MoveNext();
			}

			if(isset($result_genre)){
				
				while (!$result_genre->EOF) {
					$genreList[$result_genre->GetRowAssoc(0)['genre_id']] = $result_genre->GetRowAssoc(0)['setting_value'];
	
					$result_genre->MoveNext();
				}
				
				$templateMgr->setData('submissionFileGenres', $genreList);	

			}else{
				$templateMgr->setData('isReviewAttachment', TRUE); // SETA A VARIÁVEL PARA TRUE POIS ELA É VERIFICADA NO TEMPLATE PARA NÃO EXIBIR OS COMPONENTES
			}

		

		}

		if ($fileStage == 15) { // AUTOR SUBMETENDO REVISÃO

			$result = $userDao->retrieve(
				<<<QUERY
				SELECT A.genre_id, setting_value
				FROM ojs.genre_settings A
				LEFT JOIN ojs.genres B
				ON B.genre_id = A.genre_id
				WHERE locale = '$locale' AND entry_key LIKE 'AVAL_AUTOR%'
				QUERY
			);
			while (!$result->EOF) {
				$genreList[$result->GetRowAssoc(0)['genre_id']] = $result->GetRowAssoc(0)['setting_value'];

				$result->MoveNext();
			}
			
			$templateMgr->setData('submissionFileGenres', $genreList);

			$templateMgr->setData('alert', 'É obrigatória a submissão de uma carta ao editor associado escolhendo o componete "Alterações realizadas"');


		}		
		if ($fileStage == 18) {  // UPLOADS NO BOX DISCUSSÃO 

			if($stageId == 5){

				$autor = $userDao->retrieve( // VERIFICA SE O PERFIL É DE AUTOR PARA EXIBIR SOMENTE OS COMPONENTES DO PERFIL
					<<<QUERY
					SELECT g.user_group_id , g.user_id 
					FROM ojs.user_user_groups g
					WHERE g.user_group_id = 14 AND user_id = $userId
					QUERY
				);

				$editor_assistente = $userDao->retrieve( // VERIFICA SE O PERFIL É DE ASSISTENTE EDITORIAL PARA EXIBIR SOMENTE OS COMPONENTES DO PERFIL
					<<<QUERY
					SELECT g.user_group_id , g.user_id
					FROM ojs.user_user_groups g
					WHERE g.user_group_id = 24 AND user_id = $userId
					QUERY
				);

				if($autor->_numOfRows > 0){
					$result = $userDao->retrieve(
						<<<QUERY
						SELECT A.genre_id, setting_value
						FROM ojs.genre_settings A
						LEFT JOIN ojs.genres B
						ON B.genre_id = A.genre_id
						WHERE locale = '$locale' AND entry_key LIKE 'EDITORACAO_AUTOR%'							
						QUERY
					);

					while (!$result->EOF) {
						$genreList[$result->GetRowAssoc(0)['genre_id']] = $result->GetRowAssoc(0)['setting_value'];

						$result->MoveNext();
					}

					$templateMgr->setData('submissionFileGenres', $genreList);
				}elseif($editor_assistente->_numOfRows > 0) {
					$result = $userDao->retrieve(
						<<<QUERY
						SELECT A.genre_id, setting_value
						FROM ojs.genre_settings A
						LEFT JOIN ojs.genres B
						ON B.genre_id = A.genre_id
						WHERE locale = '$locale' AND entry_key LIKE 'EDITORACAO_ASSIST_ED%'							
						QUERY
					);

					while (!$result->EOF) {
						$genreList[$result->GetRowAssoc(0)['genre_id']] = $result->GetRowAssoc(0)['setting_value'];

						$result->MoveNext();
					}

					$templateMgr->setData('submissionFileGenres', $genreList);	
				}else{
					$templateMgr->setData('isReviewAttachment', TRUE); // SETA A VARIÁVEL PARA TRUE POIS ELA É VERIFICADA NO TEMPLATE PARA NÃO EXIBIR OS COMPONENTES
				}


			}elseif($stageId == 4){			
	
				$result = $userDao->retrieve( // VERIFICA SE O PERFIL É DE AUTOR PARA EXIBIR SOMENTE OS COMPONENTES DO PERFIL	
					<<<QUERY
					SELECT g.user_group_id , g.user_id 
					FROM ojs.user_user_groups g
					WHERE g.user_group_id = 14 AND user_id = $userId
					QUERY
				);			
	
				if($result->_numOfRows > 0){
					$result = $userDao->retrieve(
						<<<QUERY
						SELECT A.genre_id, setting_value
						FROM ojs.genre_settings A
						LEFT JOIN ojs.genres B
						ON B.genre_id = A.genre_id
						WHERE locale = '$locale' AND entry_key LIKE 'EDICAO_AUTOR%'							
						QUERY
					);	
					while (!$result->EOF) {
						$genreList[$result->GetRowAssoc(0)['genre_id']] = $result->GetRowAssoc(0)['setting_value'];
		
						$result->MoveNext();
					}
					
					$templateMgr->setData('submissionFileGenres', $genreList);	
				}else{
					$templateMgr->setData('isReviewAttachment', TRUE); // SETA A VARIÁVEL PARA TRUE POIS ELA É VERIFICADA NO TEMPLATE PARA NÃO EXIBIR OS COMPONENTES
				}
				
			}else{
				$templateMgr->setData('isReviewAttachment', TRUE); // SETA A VARIÁVEL PARA TRUE POIS ELA É VERIFICADA NO TEMPLATE PARA NÃO EXIBIR OS COMPONENTES
			}
				
		}

	}

	function pkp_services_pkpuserservice_getmany($hookName, $args)
	{
		$refObject   = new ReflectionObject($args[1]);
		$refReviewStageId = $refObject->getProperty('reviewStageId');
		$refReviewStageId->setAccessible( true );
		$reviewStageId = $refReviewStageId->getValue($args[1]);

		if (!$reviewStageId && strpos($_SERVER["HTTP_REFERER"], 'submission/wizard')  ){
			$refObject   = new ReflectionObject($args[1]);
			$refColumns = $refObject->getProperty('columns');
			$refColumns->setAccessible( true );
			$columns = $refColumns->getValue($args[1]);
			$columns[] = Capsule::raw("trim(concat(ui1.setting_value, ' ', COALESCE(ui2.setting_value, ''))) AS instituicao");
			$columns[] = Capsule::raw('\'ojs\' AS type');
			$refColumns->setValue($args[1], $columns);
	
			$cspQuery = Capsule::table(Capsule::raw('csp.Pessoa p'));
			$cspQuery->leftJoin('users as u', function ($join) {
				$join->on('u.email', '=', 'p.email');
			});
			$cspQuery->whereNull('u.email');
			$cspQuery->whereIn('p.permissao', [0,2,3]);
	
			$refSearchPhrase = $refObject->getProperty('searchPhrase');
			$refSearchPhrase->setAccessible( true );
			$words = $refSearchPhrase->getValue($args[1]);
			if ($words) {
				$words = explode(' ', $words);
				if (count($words)) {
					foreach ($words as $word) {
						$cspQuery->where(function($q) use ($word) {
							$q->where(Capsule::raw('lower(p.nome)'), 'LIKE', "%{$word}%")
								->orWhere(Capsule::raw('lower(p.email)'), 'LIKE', "%{$word}%")
								->orWhere(Capsule::raw('lower(p.orcid)'), 'LIKE', "%{$word}%");
						});
					}
				}
			}
	
			$locale = AppLocale::getLocale();
			$args[0]->leftJoin('user_settings as ui1', function ($join) use ($locale) {
				$join->on('ui1.user_id', '=', 'u.user_id')
					->where('ui1.setting_name', '=', 'instituicao1')
					->where('ui1.locale', '=', $locale);
			});
			$args[0]->leftJoin('user_settings as ui2', function ($join) use ($locale) {
				$join->on('ui2.user_id', '=', 'u.user_id')
					->where('ui2.setting_name', '=', 'instituicao2')
					->where('ui2.locale', '=', $locale);
			});

			if (property_exists($args[1], 'countOnly')) {
				$refCountOnly = $refObject->getProperty('countOnly');
				$refCountOnly->setAccessible( true );
				if ($refCountOnly->getValue($args[1])) {
					$cspQuery->select(['p.idPessoa']);
					$args[0]->select(['u.user_id'])
						->groupBy('u.user_id');
				}
			} else {
				$userDao = DAORegistry::getDAO('UserDAO');
				// retrieve all columns of table users
				$result = $userDao->retrieve(
					<<<QUERY
					SELECT `COLUMN_NAME`
					  FROM `INFORMATION_SCHEMA`.`COLUMNS` 
					 WHERE `TABLE_SCHEMA`='ojs' 
					   AND `TABLE_NAME`='users';
					QUERY
				);
				while (!$result->EOF) {
					$columnsNames[$result->GetRowAssoc(0)['column_name']] = 'null';
					$result->MoveNext();
				}
				// assign custom values to columns
				$columnsNames['user_id'] = "CONCAT('CSP|',p.idPessoa)";
				$columnsNames['email'] = 'p.email';
				$columnsNames['user_given'] = "SUBSTRING_INDEX(SUBSTRING_INDEX(p.nome, ' ', 1), ' ', -1)";
				$columnsNames['user_family'] = "TRIM( SUBSTR(p.nome, LOCATE(' ', p.nome)) )";
				$columnsNames['instituicao'] = 'p.instituicao1';
				$columnsNames['type'] = '\'csp\'';
				foreach ($columnsNames as $name => $value) {
					$cspQuery->addSelect(Capsule::raw($value . ' AS ' . $name));
				}
				$args[0]->select($columns)
					->groupBy('u.user_id', 'user_given', 'user_family');
			}
	
			$subOjsQuery = Capsule::table(Capsule::raw(
				<<<QUERY
				(
					{$args[0]->toSql()}
					UNION
					{$cspQuery->toSql()}
				) as u
				QUERY
			));
			$subOjsQuery->mergeBindings($args[0]);
			$subOjsQuery->mergeBindings($cspQuery);
			$refColumns->setValue($args[1], ['*']);
			$args[0] = $subOjsQuery;
		}
	}

	function userDAO__returnUserFromRowWithData($hookName, $args)
	{
		list($user, $row) = $args;
		if (isset($row['type'])) {
			if ($row['type'] == 'csp') {
				$locale = AppLocale::getLocale();
				$user->setData('id', (int)explode('|', $row['user_id'])[1]);
				$user->setData('familyName', [$locale => $row['user_family']]);
				$user->setData('givenName', [$locale => $row['user_given']]);
			}
			$user->setData('type', $row['type']);
			$user->setData('instituicao', $row['instituicao']);
		}elseif(isset($row['assigns'])){
			$user->setData('assigns', $row['assigns']);
		}
	}

	function userstageassignmentdao_filterusersnotassignedtostageinusergroup($hookName, $args){
		$args[0] = <<<QUERY
					SELECT q1.*, COALESCE(q2.assigns,0) AS assigns FROM ({$args[0]}) q1
					LEFT JOIN (SELECT COUNT(*) AS assigns, user_id
					FROM ojs.stage_assignments a
					JOIN ojs.submissions s
					ON s.submission_id = a.submission_id AND s.stage_id <= 3
					WHERE a.user_group_id = ?
					GROUP BY a.user_id) q2
					ON q1.user_id = q2.user_id					
					QUERY;
		$args[1][] = $args[1][10];
		

	}

	function user_getProperties_values($hookName, $args)
	{
		list(&$values, $user) = $args;
		$type = $user->getData('type');
		if ($type) {
			$values['type'] = $type;
			$values['instituicao'] = $user->getData('instituicao');
		}
	}

	public function authorform_initdata($hookName, $args)
	{
		$request = \Application::get()->getRequest();
		$type = $request->getUserVar('type');
		if ($type != 'csp') {
			return;
		}

		$form = $args[0];
		$form->setTemplate($this->getTemplateResource('authorFormAdd.tpl'));

		$userDao = DAORegistry::getDAO('UserDAO');
		$userCsp = $userDao->retrieve(
			<<<QUERY
			SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(p.nome, ' ', 1), ' ', -1) as given_name,
					TRIM( SUBSTR(p.nome, LOCATE(' ', p.nome)) ) family_name,
					email, orcid,
					TRIM(CONCAT(p.instituicao1, ' ', p.instituicao2)) AS affiliation
				FROM csp.Pessoa p
				WHERE p.idPessoa = ?
			QUERY,
			[(int) $request->getUserVar('userId')]
		)->GetRowAssoc(0);
		$locale = AppLocale::getLocale();
		$form->setData('givenName', [$locale => $userCsp['given_name']]);
		$form->setData('familyName', [$locale => $userCsp['family_name']]);
		$form->setData('affiliation', [$locale => $userCsp['affiliation']]);
		$form->setData('email', $userCsp['email']);
		$form->setData('orcid', $userCsp['orcid']);

		$args[0] = $form;
	}

	/**
	 * @copydoc Plugin::getDisplayName()
	 */
	function getDisplayName() {
		return __('plugins.generic.CspSubmission.displayName');
	}

	/**
	 * @copydoc Plugin::getDescription()
	 */
	function getDescription() {
		return __('plugins.generic.CspSubmission.description');
	}


	function workflowFieldEdit($hookName, $params) {
		$smarty =& $params[1];
		$output =& $params[2];
		$output .= $smarty->fetch($this->getTemplateResource('ExclusaoPrefixo.tpl'));
		return false;
	}

	/**
	 * Insert Campo1 field into author submission step 3 and metadata edit form
	 */
	function metadataFieldEdit($hookName, $params) {

		$submissionDAO = Application::getSubmissionDAO();
		$request = \Application::get()->getRequest();
		/** @val Submission */
		$submission = $submissionDAO->getById($request->getUserVar('submissionId'));
		$publication = $submission->getCurrentPublication();
		$sectionId = $publication->getData('sectionId');

		$smarty =& $params[1];
		$output =& $params[2];
		//$output .= $smarty->fetch($this->getTemplateResource('RemovePrefixoTitulo.tpl'));
		
		if($sectionId == 5){
			$output .= $smarty->fetch($this->getTemplateResource('Revisao.tpl'));
		}
		
		if($sectionId == 4){
			$output .= $smarty->fetch($this->getTemplateResource('Tema.tpl'));
			$output .= $smarty->fetch($this->getTemplateResource('codigoTematico.tpl'));
		}

		$output .= $smarty->fetch($this->getTemplateResource('conflitoInteresse.tpl'));
		//$output .= $smarty->fetch($this->getTemplateResource('FonteFinanciamento.tpl'));
		$output .= $smarty->fetch($this->getTemplateResource('agradecimentos.tpl'));
		
		if($sectionId == 6){
			$output .= $smarty->fetch($this->getTemplateResource('codigoArtigoRelacionado.tpl'));
		}

		$output .= $smarty->fetch($this->getTemplateResource('InclusaoAutores.tpl'));
		
		
		return false;
	}

 	function metadataReadUserVars($hookName, $params) {
		$userVars =& $params[1];
		$userVars[] = 'conflitoInteresse';
		//$userVars[] = 'conflitoInteresseQual';
		//$userVars[] = 'FonteFinanciamento';
		//$userVars[] = 'FonteFinanciamentoQual';		
		$userVars[] = 'agradecimentos';
		$userVars[] = 'codigoTematico';
		$userVars[] = 'Tema';
		$userVars[] = 'codigoArtigoRelacionado';
		$userVars[] = 'CodigoArtigo';
		$userVars[] = 'doi';
		
		return false;
	} 

 	function metadataExecuteStep3($hookName, $params) {
		$form =& $params[0];
		$article = $form->submission;
		$article->setData('conflitoInteresse', $form->getData('conflitoInteresse'));
		//$article->setData('conflitoInteresseQual', $form->getData('conflitoInteresseQual'));
		//$article->setData('FonteFinanciamento', $form->getData('FonteFinanciamento'));
		//$article->setData('FonteFinanciamentoQual', $form->getData('FonteFinanciamentoQual'));		
		$article->setData('agradecimentos', $form->getData('agradecimentos'));
		$article->setData('codigoTematico', $form->getData('codigoTematico'));
		$article->setData('Tema', $form->getData('Tema'));
		$article->setData('codigoArtigoRelacionado', $form->getData('codigoArtigoRelacionado'));
		$article->setData('doi', $form->getData('doi'));
		
		return false;
	} 

	function metadataExecuteStep4($hookName, $params) {
		$form =& $params[0];
		$article = $form->submission;				
		$userDao = DAORegistry::getDAO('UserDAO');
		$result = $userDao->retrieve(
			<<<QUERY
			SELECT CONCAT(LPAD(count(*)+1, CASE WHEN count(*) > 9999 THEN 5 ELSE 4 END, 0), '/', DATE_FORMAT(now(), '%y')) code
			FROM submissions
			WHERE YEAR(date_submitted) = YEAR(now())
			QUERY
		);
		$article->setData('CodigoArtigo', $result->GetRowAssoc(false)['code']);
		
		
		return false;
	}
	

	/**
	 * Init article Campo1
	 */
 	function metadataInitData($hookName, $params) {
		$form =& $params[0];
		$article = $form->submission;
		$this->sectionId = $article->getData('sectionId');
		$form->setData('conflitoInteresse', $article->getData('conflitoInteresse'));
		//$form->setData('conflitoInteresseQual', $article->getData('conflitoInteresseQual'));
		//$form->setData('FonteFinanciamento', $article->getData('FonteFinanciamento'));				
		//$form->setData('FonteFinanciamentoQual', $article->getData('FonteFinanciamentoQual'));			
		$form->setData('agradecimentos', $article->getData('agradecimentos'));
		$form->setData('codigoTematico', $article->getData('codigoTematico'));
		$form->setData('Tema', $article->getData('Tema'));	
		$form->setData('codigoArtigoRelacionado', $article->getData('codigoArtigoRelacionado'));
		$form->setData('doi', $article->getData('doi'));
		
		return false;
	} 


	function publicationEdit($hookName, $params) {
		$params[0]->setData('agradecimentos', $params[3]->_requestVars["agradecimentos"]);
		$params[1]->setData('agradecimentos', $params[3]->_requestVars["agradecimentos"]);
		$params[2]["agradecimentos"] = $params[3]->_requestVars["agradecimentos"];

		$params[0]->setData('doi', $params[3]->_requestVars["doi"]);
		$params[1]->setData('doi', $params[3]->_requestVars["doi"]);
		$params[2]["doi"] = $params[3]->_requestVars["doi"];

		$params[0]->setData('codigoTematico', $params[3]->_requestVars["codigoTematico"]);
		$params[1]->setData('codigoTematico', $params[3]->_requestVars["codigoTematico"]);
		$params[2]["codigoTematico"] = $params[3]->_requestVars["codigoTematico"];

		$params[0]->setData('codigoArtigoRelacionado', $params[3]->_requestVars["codigoArtigoRelacionado"]);
		$params[1]->setData('codigoArtigoRelacionado', $params[3]->_requestVars["codigoArtigoRelacionado"]);
		$params[2]["codigoArtigoRelacionado"] = $params[3]->_requestVars["codigoArtigoRelacionado"];

		$params[0]->setData('conflitoInteresse', $params[3]->_requestVars["conflitoInteresse"]);
		$params[1]->setData('conflitoInteresse', $params[3]->_requestVars["conflitoInteresse"]);
		$params[2]["conflitoInteresse"] = $params[3]->_requestVars["conflitoInteresse"];

		return false;
	}
	/**
	 * Add check/validation for the Campo1 field (= 6 numbers)
	 */
	function addCheck($hookName, $params) {
		$form =& $params[0];

		if($this->sectionId == 4){		
			$form->addCheck(new FormValidatorLength($form, 'codigoTematico', 'required', 'plugins.generic.CspSubmission.codigoTematico.Valid', '>', 0));			
			$form->addCheck(new FormValidatorLength($form, 'Tema', 'required', 'plugins.generic.CspSubmission.Tema.Valid', '>', 0));			
		}

		if($this->sectionId == 6){		
			$form->addCheck(new FormValidatorLength($form, 'codigoArtigoRelacionado', 'required', 'plugins.generic.CspSubmission.codigoArtigoRelacionado.Valid', '>', 0));			
		}

		$form->addCheck(new FormValidatorCustom($form, 'doi', 'optional', 'plugins.generic.CspSubmission.doi.Valid', function($doi) {
			if (!filter_var($doi, FILTER_VALIDATE_URL)) {
				if (strpos(reset($doi), 'doi.org') === false){
					$doi = 'http://dx.doi.org/'.reset($doi);
				} elseif (strpos(reset($doi),'http') === false) {
					$doi = 'http://'.reset($doi);
				} else {
					return false;
				}				
			}

			$client = HttpClient::create();
			$response = $client->request('GET', $doi);
			$statusCode = $response->getStatusCode();			
			return in_array($statusCode,[303,200]);
		}));

		
		return false;
	}

	public function submissionfilesuploadformValidate($hookName, $args) {
		// Retorna o tipo do arquivo enviado
		$genreId = $args[0]->getData('genreId');
		switch($genreId) {
			case 1: // CORPO DO ARTIGO
			case 13: // TABELA
			case 14: // QUADRO
			case 19: // NOVA VERSÃO CORPO DO ARTIGO
			case 20: // NOVA VERSÃO TABELA
			case 21: // NOVA VERSÃO QUADRO
				if (($_FILES['uploadedFile']['type'] <> 'application/msword') /*Doc*/
				and ($_FILES['uploadedFile']['type'] <> 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') /*docx*/
				and ($_FILES['uploadedFile']['type'] <> 'application/vnd.oasis.opendocument.text')/*odt*/) {
					$args[0]->addError('genreId',
						__('plugins.generic.CspSubmission.SectionFile.invalidFormat.AticleBody')
					);
					break;
				}

				$submissionDAO = Application::getSubmissionDAO();
				$request = \Application::get()->getRequest();
				/** @val Submission */
				$submission = $submissionDAO->getById($request->getUserVar('submissionId'));
				$publication = $submission->getCurrentPublication();
				$sectionId = $publication->getData('sectionId');
				$sectionDAO = DAORegistry::getDAO('SectionDAO');
				$section = $sectionDAO->getById($sectionId);
				$wordCount = $section->getData('wordCount');

				if ($wordCount) {
					$formato = explode('.', $_FILES['uploadedFile']['name']);
					$formato = trim(strtolower(end($formato)));
	
					$readers = array('docx' => 'Word2007', 'odt' => 'ODText', 'rtf' => 'RTF', 'doc' => 'ODText');
					$doc = \PhpOffice\PhpWord\IOFactory::load($_FILES['uploadedFile']['tmp_name'], $readers[$formato]);
					$html = new PhpOffice\PhpWord\Writer\HTML($doc);
					$contagemPalavras = str_word_count(strip_tags($html->getWriterPart('Body')->write()));
					if ($contagemPalavras > $wordCount) {
						$phrase = __('plugins.generic.CspSubmission.SectionFile.errorWordCount', [
							'sectoin' => $section->getTitle($publication->getData('locale')),
							'max'     => $wordCount,
							'count'   => $contagemPalavras
						]);
						$args[0]->addError('genreId', $phrase);
					}
				}
				break;
			case 10: // Fotografia
			case 24: // Nova versão Fotografia
				if (!in_array($_FILES['uploadedFile']['type'], ['image/bmp', 'image/tiff', 'image/svg+xml'])) {
					$args[0]->addError('genreId',
						__('plugins.generic.CspSubmission.SectionFile.invalidFormat.Image')
					);
				}
				break;		
			case 15: // Fluxograma
			case 25: // Nova versão fluxograma
				if (($_FILES['uploadedFile']['type'] <> 'application/msword') /*doc*/
					and ($_FILES['uploadedFile']['type'] <> 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') /*docx*/
					and ($_FILES['uploadedFile']['type'] <> 'application/vnd.oasis.opendocument.text')/*odt*/
					and ($_FILES['uploadedFile']['type'] <> 'image/x-eps')/*eps*/
					and ($_FILES['uploadedFile']['type'] <> 'image/svg+xml')/*svg*/
					and ($_FILES['uploadedFile']['type'] <> 'image/wmf')/*wmf*/) {
					$args[0]->addError('genreId',
						__('plugins.generic.CspSubmission.SectionFile.invalidFormat.Flowchart')
					);
				}
				break;	
			case 16: // Gráfico
			case 26: // Nova versão gráfico
				$_FILES['uploadedFile']['type'];
				if (($_FILES['uploadedFile']['type'] <> 'application/vnd.ms-excel') /*xls*/
					and ($_FILES['uploadedFile']['type'] <> 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') /*xlsx*/
					and ($_FILES['uploadedFile']['type'] <> 'application/vnd.oasis.opendocument.spreadsheet')/*ods*/
					and ($_FILES['uploadedFile']['type'] <> 'image/x-eps')/*eps*/
					and ($_FILES['uploadedFile']['type'] <> 'image/svg+xml')/*svg*/
					and ($_FILES['uploadedFile']['type'] <> 'image/wmf')/*wmf*/) {
					$args[0]->addError('genreId',
						__('plugins.generic.CspSubmission.SectionFile.invalidFormat.Chart')
					);
				}
				break;	
			case 17: // Mapa
			case 27: // Nova versão mapa
				$_FILES['uploadedFile']['type'];
				if (($_FILES['uploadedFile']['type'] <> 'image/x-eps')/*eps*/
					and ($_FILES['uploadedFile']['type'] <> 'image/svg+xml')/*svg*/
					and ($_FILES['uploadedFile']['type'] <> 'image/wmf')/*wmf*/) {
					$args[0]->addError('genreId',
						__('plugins.generic.CspSubmission.SectionFile.invalidFormat.Map')
					);
				}
				break;		
				case '46': 	// PDF para avaliação
				case '30': 	// Nova versão PDF
					$request = \Application::get()->getRequest();
					$submissionId = $request->getUserVar('submissionId');
					$userDao = DAORegistry::getDAO('UserDAO');

					if (($_FILES['uploadedFile']['type'] <> 'application/pdf')/*PDF*/) {
						$args[0]->addError('typeId',
							__('plugins.generic.CspSubmission.SectionFile.invalidFormat.PDF')
						);
					}else{
						if($genreId == '46'){ // QUANDO SECRETARIA SOBRE UM PDF NO ESTÁGIO DE SUBMISSÃO, A SUBMISSÃO É DESIGNADA PARA TODOS OS EDITORES DA REVISTA

							$result = $userDao->retrieve(
								<<<QUERY
								SELECT s.user_group_id , g.user_id, a.user_id as assigned
								FROM ojs.user_user_groups g
								LEFT JOIN ojs.user_group_settings s
								ON s.user_group_id = g.user_group_id
								LEFT JOIN ojs.stage_assignments a
								ON g.user_id = a.user_id AND a.submission_id = $submissionId
								WHERE s.setting_value = 'Editor da revista'
								QUERY
							);
							while (!$result->EOF) {

								if($result->GetRowAssoc(0)['assigned'] == NULL){

									$userGroupId = $result->GetRowAssoc(0)['user_group_id'];
									$userId = $result->GetRowAssoc(0)['user_id'];

									$stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO'); /* @var $stageAssignmentDao StageAssignmentDAO */
									$stageAssignment = $stageAssignmentDao->newDataObject();
									$stageAssignment->setSubmissionId($submissionId);
									$stageAssignment->setUserGroupId($userGroupId);
									$stageAssignment->setUserId($userId);
									$stageAssignment->setRecommendOnly(0);
									$stageAssignment->setCanChangeMetadata(1);
									$stageAssignmentDao->insertObject($stageAssignment);

									$submissionDAO = Application::getSubmissionDAO();
									$submission = $submissionDAO->getById($submissionId);

									$userDao = DAORegistry::getDAO('UserDAO'); /* @var $userDao UserDAO */
									$assignedUser = $userDao->getById($userId);
									$userGroupDao = DAORegistry::getDAO('UserGroupDAO'); /* @var $userGroupDao UserGroupDAO */
									$userGroup = $userGroupDao->getById($userGroupId);

									import('lib.pkp.classes.log.SubmissionLog');
									SubmissionLog::logEvent($request, $submission, SUBMISSION_LOG_ADD_PARTICIPANT, 'submission.event.participantAdded', array('name' => $assignedUser->getFullName(), 'username' => $assignedUser->getUsername(), 'userGroupName' => $userGroup->getLocalizedName()));
									
								}

								$result->MoveNext();
							}
						}
						if($genreId == 30){ // QUANDO SECRETARIA SOBRE UM PDF NO ESTÁGIO DE AVALIAÇÃO, O EDITOR ASSOCIADO É NOTIFICADO
							$locale = AppLocale::getLocale();

							$submissionDAO = Application::getSubmissionDAO();
							$submission = $submissionDAO->getById($submissionId);
							$submissionTitle = $submission->_data["publications"][0]->_data["title"][$locale];
							$contextId = $submission->_data["contextId"];

							$userDao = DAORegistry::getDAO('UserDAO');
							$result = $userDao->retrieve(
								<<<QUERY
								SELECT u.email, x.setting_value as name
								FROM ojs.stage_assignments a
								LEFT JOIN ojs.users u
								ON a.user_id = u.user_id
								LEFT JOIN (SELECT user_id, setting_value FROM ojs.user_settings WHERE setting_name = 'givenName' AND locale = '$locale') x
								ON x.user_id = u.user_id
								WHERE submission_id = $submissionId AND user_group_id = 5
								QUERY
							);

							import('lib.pkp.classes.mail.MailTemplate');

							while (!$result->EOF) {

								$mail = new MailTemplate('AVALIACAO_AUTOR_EDITOR_ASSOC');
								$mail->addRecipient($result->GetRowAssoc(0)['email'], $result->GetRowAssoc(0)['name']);
								$mail->setBody(str_replace('{$submissionTitle}',$submissionTitle,$mail->_data["body"]));

								if (!$mail->send()) {
									import('classes.notification.NotificationManager');
									$notificationMgr = new NotificationManager();
									$notificationMgr->createTrivialNotification($request->getUser()->getId(), NOTIFICATION_TYPE_ERROR, array('contents' => __('email.compose.error')));
								}

								$result->MoveNext();
							}
						}
					}
					break;															
					case '':
						$args[0]->setData('genreId',47);
						$args[1] = true;	
					break;
				return true;										
		}

		if (!defined('SESSION_DISABLE_INIT')) {
			$request = \Application::get()->getRequest();
			$user = $request->getUser();

			if (!$args[0]->isValid() && $user) {
				import('classes.notification.NotificationManager');
				$notificationManager = new NotificationManager();
				$notificationManager->createTrivialNotification(
					$user->getId(),
					NOTIFICATION_TYPE_FORM_ERROR,
					['contents' => $args[0]->getErrorsArray()]
				);
			}
		}
		if (!$args[0]->isValid()) {
			return true;
		}
		return false;
	}

	public function SubmissionHandler_saveSubmit($hookName, $args)
	{
		$this->article = $args[1];
	}

	function fileManager_downloadFile($hookName, $args)
	{
		list($filePath, $mediaType, $inline, $result, $fileName) = $args;
		if (is_readable($filePath)) {			
			if ($mediaType === null) {
				// If the media type wasn't specified, try to detect.
				$mediaType = PKPString::mime_content_type($filePath);
				if (empty($mediaType)) $mediaType = 'application/octet-stream';
			}
			if ($fileName === null) {
				// If the filename wasn't specified, use the server-side.
				$fileName = basename($filePath);
			}
			preg_match('/\/articles\/(?P<id>\d+)\//',$filePath,$matches);
			if ($matches) {
				$submissionDao = DAORegistry::getDAO('SubmissionFileDAO');
				$result = $submissionDao->retrieve(
					<<<QUERY
					SELECT REPLACE(setting_value,'/','_') AS codigo_artigo
					FROM ojs.submission_settings
					WHERE setting_name = 'CodigoArtigo' AND submission_id = ?
					QUERY, 
					[$matches['id']]
				);
				$a = $result->GetRowAssoc(false);
				$fileName = $a['codigo_artigo'].'_'.$fileName;
			}
			// Stream the file to the end user.
			header("Content-Type: $mediaType");
			header('Content-Length: ' . filesize($filePath));
			header('Accept-Ranges: none');
			header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename=\"$fileName\"");
			header('Cache-Control: private'); // Workarounds for IE weirdness
			header('Pragma: public');
			FileManager::readFileFromPath($filePath, true);
			$returner = true;
		} else {
			$returner = false;
		}
		HookRegistry::call('FileManager::downloadFileFinished', array(&$returner));
		return true;
	}
}
