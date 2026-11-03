<?php

namespace Modules\AlertINT\Actions;

use CController;
use CControllerResponseData;
use Exception;
use Modules\AlertINT\Includes\TestProblem;

/**
 * Test button: raises (value=1) or resolves (value=0) the problem on the
 * "AlertINT test" host. The page then follows it with alertint.test.status.
 */
class TestRun extends CController {

	protected function checkInput(): bool {
		$ok = $this->validateInput(['value' => 'required|in 0,1']);

		if (!$ok) {
			$this->respond(['ok' => false, 'error' => _('Invalid request.')]);
		}

		return $ok;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
	}

	protected function doAction(): void {
		try {
			$this->respond(['ok' => true] + TestProblem::send((int) $this->getInput('value')));
		}
		catch (Exception $e) {
			$this->respond(['ok' => false, 'error' => $e->getMessage()]);
		}
	}

	private function respond(array $data): void {
		$this->setResponse(new CControllerResponseData(['main_block' => json_encode($data)]));
	}
}
