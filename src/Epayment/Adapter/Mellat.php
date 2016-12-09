<?php
namespace Tartan\Epayment\Adapter;

use SoapClient;
use SoapFault;
use Tartan\Epayment\Adapter\Mellat\Exception;
use Illuminate\Support\Facades\Log;

class Mellat extends AdapterAbstract implements AdapterInterface
{
	protected $WSDL = 'https://bpm.shaparak.ir/pgwchannel/services/pgw?wsdl';
	protected $endPoint = 'https://pgw.bpm.bankmellat.ir/pgwchannel/startpay.mellat';

	protected $testWSDL = 'http://banktest.ir/gateway/mellat/ws?wsdl';
	protected $testEndPoint = 'http://banktest.ir/gateway/mellat/gate';

	protected $reverseSupport = true;

	/**
	 * @return array
	 * @throws Exception
	 */
	protected function requestToken()
	{
		if($this->getInvoice()->checkForRequestToken() == false) {
			throw new Exception('epayment::epayment.could_not_request_payment');
		}

		$this->checkRequiredParameters([
			'terminal_id',
			'username',
			'password',
			'order_id',
			'amount',
			'redirect_url',
		]);

		$sendParams = [
			'terminalId'     => $this->terminal_id,info
			'userName'       => $this->username,
			'userPassword'   => $this->password,
			'orderId'        => $this->order_id,
			'amount'         => intval($this->amount),
			'localDate'      => $this->local_date ? $this->local_date : date('Ymd'),
			'localTime'      => $this->local_time ? $this->local_time : date('His'),
			'additionalData' => $this->additional_data ? $this->additional_data : '',
			'callBackUrl'    => $this->redirect_url,
			'payerId'        => intval($this->payer_id),
		];

		try {
			$soapClient = new SoapClient($this->getWSDL());

			Log::debug('bpPayRequest call', $sendParams);

			$response = $soapClient->bpPayRequest($sendParams);

			if (isset($response->return)) {
				Log::info('bpPayRequest response', ['return' => $response->return]);

				$response = explode(',', $response->return);

				if ($response[0] == 0) {
					$this->getInvoice()->setReferenceId($response[1]); // update invoice reference id
					return $response[1];
				}
				else {
					throw new Exception($response[0]);
				}
			} else {
				throw new Exception('epayment::epayment.mellat.errors.invalid_response');
			}
		} catch (SoapFault $e) {
			throw new Exception('SoapFault: ' . $e->getMessage() . ' #' . $e->getCode(), $e->getCode());
		}
	}

	/**
	 * @return mixed
	 */
	protected function generateForm ()
	{
		$refId = $this->requestToken();

		return view('epayment::mellat-form', [
			'endPoint'    => $this->getEndPoint(),
			'refId'       => $refId,
			'submitLabel' => !empty($this->submit_label) ? $this->submit_label : trans("epayment::epayment.goto_gate"),
			'autoSubmit'  => boolval($this->auto_submit)
		]);
	}

	/**
	 * @return bool
	 * @throws Exception
	 */
	protected function verifyTransaction ()
	{
		if($this->getInvoice()->checkForVerify() == false) {
			throw new Exception('epayment::epayment.could_not_verify_payment');
		}

		$this->checkRequiredParameters([
			'terminal_id',
			'username',
			'password',
			'RefId',
			'ResCode',
			'SaleOrderId',
			'SaleReferenceId',
			'CardHolderInfo'
		]);

		$sendParams = [
			'terminalId'      => $this->terminal_id,
			'userName'        => $this->username,
			'userPassword'    => $this->password,
			'orderId'         => $this->SaleOrderId, // same as SaleOrderId
			'saleOrderId'     => $this->SaleOrderId,
			'saleReferenceId' => $this->SaleReferenceId
		];

		$this->getInvoice()->setCardNumber($this->CardHolderInfo);

		try {
			$soapClient = new SoapClient($this->getWSDL());

			Log::debug('bpVerifyRequest call', $sendParams);
			$response   = $soapClient->bpVerifyRequest($sendParams);

			if (isset($response->return)) {
				Log::info('bpVerifyRequest response', ['return' => $response->return]);

				if($response->return != '0') {
					throw new Exception($response->return);
				} else {
					$this->getInvoice()->setVerified();
					return true;
				}
			} else {
				throw new Exception('epayment::epayment.mellat.errors.invalid_response');
			}

		} catch (SoapFault $e) {

			throw new Exception('SoapFault: ' . $e->getMessage() . ' #' . $e->getCode(), $e->getCode());
		}
	}

	/**
	 * @return bool
	 * @throws Exception
	 */
	public function inquiryTransaction ()
	{
		if($this->getInvoice()->checkForInquiry() == false) {
			throw new Exception('epayment::epayment.could_not_inquiry_payment');
		}

		$this->checkRequiredParameters([
			'terminal_id',
			'terminal_user',
			'terminal_pass',
			'RefId',
			'ResCode',
			'SaleOrderId',
			'SaleReferenceId',
			'CardHolderInfo'
		]);

		$sendParams = [
			'terminalId'      => $this->terminal_id,
			'userName'        => $this->username,
			'userPassword'    => $this->password,
			'orderId'         => $this->SaleOrderId, // same as SaleOrderId
			'saleOrderId'     => $this->SaleOrderId,
			'saleReferenceId' => $this->SaleReferenceId
		];

		$this->getInvoice()->setCardNumber($this->CardHolderInfo);

		try {
			$soapClient = new SoapClient($this->getWSDL());

			Log::debug('bpInquiryRequest call', $sendParams);
			$response   = $soapClient->bpInquiryRequest($sendParams);

			if (isset($response->return)) {
				Log::info('bpInquiryRequest response', ['return' => $response->return]);
				if($response->return != '0') {
					throw new Exception($response->return);
				} else {
					$this->getInvoice()->setVerified();
					return true;
				}
			} else {
				throw new Exception('epayment::epayment.mellat.errors.invalid_response');
			}

		} catch (SoapFault $e) {

			throw new Exception('SoapFault: ' . $e->getMessage() . ' #' . $e->getCode(), $e->getCode());
		}
	}

	/**
	 * Send settle request
	 *
	 * @return bool
	 *
	 * @throws Exception
	 * @throws SoapFault
	 */
	protected function settleTransaction()
	{
		if ($this->getInvoice()->checkForAfterVerify() == false) {
			throw new Exception('epayment::epayment.could_not_settle_payment');
		}

		$this->checkRequiredParameters([
			'terminal_id',
			'username',
			'password',
			'RefId',
			'ResCode',
			'SaleOrderId',
			'SaleReferenceId',
			'CardHolderInfo'
		]);

		$sendParams = [
			'terminalId'      => $this->terminal_id,
			'userName'        => $this->username,
			'userPassword'    => $this->password,
			'orderId'         => $this->SaleOrderId, // same as orderId
			'saleOrderId'     => $this->SaleOrderId,
			'saleReferenceId' => $this->SaleReferenceId
		];

		try {
			$soapClient = new SoapClient($this->getWSDL());

			Log::debug('bpSettleRequest call', $sendParams);
			$response = $soapClient->bpSettleRequest($sendParams);

			if (isset($response->return)) {
				Log::info('bpSettleRequest response', ['return' => $response->return]);

				if($response->return == '0' || $response->return == '45') {
					$this->getInvoice()->setAfterVerified();
					return true;
				} else {
					throw new Exception($response->return);
				}
			} else {
				throw new Exception('epayment::epayment.mellat.errors.invalid_response');
			}

		} catch (\SoapFault $e) {
			throw new Exception('SoapFault: ' . $e->getMessage() . ' #' . $e->getCode(), $e->getCode());
		}

	}

	/**
	 * @return bool
	 * @throws Exception
	 */
	protected function reverseTransaction ()
	{
		if ($this->reverseSupport == false || $this->getInvoice()->checkForReverse() == false) {
			throw new Exception('epayment::epayment.could_not_reverse_payment');
		}

		$this->checkRequiredParameters([
			'terminal_id',
			'username',
			'password',
			'RefId',
			'ResCode',
			'SaleOrderId',
			'SaleReferenceId',
			'CardHolderInfo'
		]);

		$sendParams = [
			'terminalId'      => $this->terminal_id,
			'userName'        => $this->username,
			'userPassword'    => $this->password,
			'orderId'         => $this->SaleOrderId, // same as orderId
			'saleOrderId'     => $this->SaleOrderId,
			'saleReferenceId' => $this->SaleReferenceId
		];

		try {
			$soapClient = new SoapClient($this->getWSDL());

			Log::debug('bpReversalRequest call', $sendParams);
			$response = $soapClient->bpReversalRequest($sendParams);

			Log::info('bpReversalRequest response', ['return' => $response->return]);

			if (isset($response->return)){
				if ($response->return == '0' || $response->return == '45') {
					$this->getInvoice()->setReversed();
					return true;
				} else {
					throw new Exception($response->return);
				}
			} else {
				throw new Exception('epayment::epayment.mellat.errors.invalid_response');
			}

		} catch (SoapFault $e) {
			throw new Exception('SoapFault: ' . $e->getMessage() . ' #' . $e->getCode(), $e->getCode());
		}
	}

	public function getGatewayReferenceId()
	{
		$this->checkRequiredParameters([
			'SaleReferenceId',
		]);
		return $this->SaleReferenceId;
	}

	public function afterVerify()
	{
		return $this->settleTransaction();
	}
}
