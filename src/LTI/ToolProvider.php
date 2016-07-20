<?php

namespace smtech\ReflexiveCanvasLTI\LTI;

use mysqli;

use LTI_Tool_Provider;
use LTI_Context;
use LTI_Tool_Consumer;
use LTI_User;
use LTI_Data_Connector;

use smtech\ReflexiveCanvasLTI\Canvas\User;
use smtech\ReflexiveCanvasLTI\Exception\SessionException;

class ToolProvider extends LTI_Tool_Provider {

    protected
        $handlers;

    public function __construct(mysqli $mysql, array $handlers) {
        parent::__construct(LTI_Data_Connector::getDataConnector($mysql), null);
        if (!$this->isSchemaPresent($mysql)) {
            $this->loadSchema($mysql);
        }
        $this->setHandlerURL($handlers);
    }

    /**
     * [isSchemaPresent description]
	 *
     * TODO is there a _better_ way of validating this?
	 *
	 * @return boolean
	 */
	protected function isSchemaPresent($mysql) {
		$response = $mysql->query("SHOW TABLES LIKE 'lti_%'");
		return $response->num_rows >= 5;
	}

	protected function loadSchema($mysql) {
		/* help ourselves to the Composer autoloader... */
		/*
		 * FIXME I have to imagine that assuming the install directory is 'vendor' is
		 * 		 unsafe...
		 */
		if (strpos(__DIR__, '/vendor/')) {
		    $composer = require preg_replace('%(.*/vendor)/.*%', '$1/autoload.php', __DIR__);
		} else {
		    $composer = require preg_replace('%(.*)/src/.*%', '$1/vendor/autoload.php', __DIR__);
		}

		/* ...so that we can find the LTI_Tool_Provider database schema (oy!) */
		foreach (explode(';', file_get_contents(dirname($composer->findFile(LTI_Tool_Provider::class)) . '/lti-tables-mysql.sql')) as $query) {
			if (!empty(trim($query))) {
				if ($mysql->query($query) === false) {
					/*
					 * TODO should there be some sort of logging here? If _some_ tables are
					 * 		present, that will trigger reloading all tables, which will generate
					 * 		ignorable errors.
					 */
				}
			}
		}
	}

	/**
	 * Create a new tool consumer, optionally with specific key and shared
	 * secret.
	 *
	 * @param string $name Human-readable Tool Consumer name (e.g. "My School
	 *     Canvas")
	 * @param string $key (Optional) A unique key to identify this consumer
	 *     within the database
	 * @param string $secret (Optional) A shared secret to authenticate this tool
	 *     consumer
	 * @return void
	 */
	public function createConsumer($name, $key = false, $secret = false) {
		/* scan through existing consumers to be sure we're not creating a duplicate */
		$consumers = $this->getConsumers();
		$consumerExists = false;
		foreach ($consumers as $consumer) {
			if ($consumer->name == $name) {
				$consumerExists = true;
				break;
			}
		}

		if (!$consumerExists) {
			$consumer = new LTI_Tool_Consumer(
				($key ? $key : hash('md5', 'key' . time())),
				$this->data_connector,
				true
			);
			$consumer->name = $name;
			$consumer->secret = ($secret ? $secret : hash('md5', 'secret' . time()));
			$consumer->save();
            return true;
		}
        return false;
	}

	/**
	 * Update request-type/script-handler pairings
	 *
	 * This anticipates either a pair of strings or an associative array of pairs
	 *
	 * ```PHP
	 * ['requestType' => 'URL of script handling this request']
	 * ```
	 *
	 * @param string|string[] $requestType_or_list
	 * @param string $url Ignored if `$requestType_or_list` is an array,
	 *     required if `$requestType_or_list` is a string
	 */
	public function setHandlerURL($requestType_or_list, $url = null) {

		/* figure out if we have a handler => URL pair, or an associative list of handler => URL pairs */
		$list = false;
		if (is_array($requestType_or_list)) {
			$list = $requestType_or_list;
		} elseif ($url && is_string($requestType_or_list)) {
			$list[$requestType_or_list] = $url;
		}

		/* walk through our list of pairs and assign them */
		if ($list) {
			foreach($list as $request => $url) {
				switch (strtolower($request)) {
					case 'base':
					case 'launch':
					case 'dashboard':
					case 'content-item':
					case 'configure':
					case 'error':
						$this->handlers[$request] = $url;
						break;
					default:
						throw new ConfigurationException(
                            'Unknown LTI request type "' . $request . '".',
                            ConfigurationException::TOOL_PROVIDER
                        );
				}
			}

			/* default the base handler URL to the first one given, if none specified */
			if (empty($this->handlers['base'])) {
				reset($list);
				$this->handlers['base'] = strtok(current($list), '?');
			}
		} else {
			throw new ConfigurationException(
				'Expected an associative array of LTI request types and handler URLs or a single LTI request type and a handler URL.',
                ConfigurationException::TOOL_PROVIDER
			);
		}
	}

	public function assignRedirect($request) {
        $request = strtolower($request);
		if (empty($this->handlers[$request])) {
			return $this->handlers['base'] . "?lti-request=$request";
		} else {
			return $this->handlers[$request];
		}
	}

    protected function initSession() {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

	public function onLaunch() {
        $this->initSession();
        $_SESSION[__CLASS__] = [
            'consumer_key' => $this->consumer->getKey(),
            'resource_id' => $this->resource_link->getId(),
            'user_consumer_key' => $this->user->getResourceLink()->getConsumer()->getKey(),
            'user_id' => $this->user->getId(),
            'isStudent' => $this->user->isLearner(),
            'isContentItem' => false
        ];

		$this->redirectURL = $this->assignRedirect('launch');
	}

	public function onDashboard() {
		$this->redirectURL = $this->assignRedirect('dashboard');
	}

	public function onConfigure() {
		$this->redirectURL = $this->assignRedirect('configure');
	}

	public function onContentItem() {
        $this->initSession();
        $_SESSION[__CLASS__] = [
            'consumer_key' => $this->consumer->getKey(),
            'resource_id' => getGuid(),
            'resource_id_created' => false,
            'user_consumer_key' => $this->consumer->getKey(),
            'user_id' => 'System',
            'isStudent' => false,
            'isContentItem' => true,
            'lti_version' => $_POST['lti_version'],
            'return_url' => $this->return_url,
            'title' => postValue('title'),
            'text' => postValue('text'),
            'data' => postValue('data'),
            'document_targets' => $this->documentTargets
        ];

		$this->redirectURL = $this->assignRedirect('content-item');
	}

	public function onError() {
		$this->redirectURL = $this->assignRedirect('error');
	}
}