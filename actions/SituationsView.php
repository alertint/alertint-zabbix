<?php

namespace Modules\AlertINT\Actions;

use CController;
use CControllerResponseData;

class SituationsView extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return true;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		$response = new CControllerResponseData([]);
		$response->setTitle(_('AlertINT Situations'));
		$this->setResponse($response);
	}
}
