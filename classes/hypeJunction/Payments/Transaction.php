<?php

namespace hypeJunction\Payments;

use ElggEntity;
use ElggObject;

/**
 * @property string $transaction_id Unique ID of the transaction
 * @property string $payment_method Payment method used for the transaction
 * @property string $status         Current payment status of the transaction
 * @property int    $amount         Monetary value of the transaction amount
 * @property int    $processor_fee  Fee withdrawn from the amount by the processor
 * @property string $currency       Currency
 */
class Transaction extends ElggObject implements TransactionInterface {

	use SerializedMetadata;

	const SUBTYPE = 'transaction';

	/**
	 * @var ElggEntity
	 */
	protected $customer;

	/**
	 * @var ElggEntity
	 */
	protected $merchant;

	/**
	 * @var array
	 */
	protected $details;

	/**
	 * {@inheritdoc}
	 */
	protected function initializeAttributes() {
		parent::initializeAttributes();
		$this->attributes['subtype'] = self::SUBTYPE;
	}

	/**
	 * {@inheritdoc}
	 */
	public function save() {
		if (!isset($this->status)) {
			$this->status = self::STATUS_NEW;
		}

		if (!$this->transaction_id) {
			$this->transaction_id = sha1(time() . rand(10000, 99999) . serialize($this->getOrder()));
		}

		$result = parent::save();

		if ($result) {
			if ($this->customer) {
				add_entity_relationship($this->customer->guid, 'customer', $this->guid);
			}

			if ($this->merchant) {
				add_entity_relationship($this->merchant->guid, 'merchant', $this->guid);
			}
		}

		return $result;
	}

	/**
	 * {@inheritdoc}
	 */
	public function setId($transaction_id) {
		$this->transaction_id = $transaction_id;
		return $this;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getId() {
		return $this->transaction_id;
	}

	/**
	 * {@inheritdoc}
	 */
	public static function getFromId($transaction_id) {

		if (empty($transaction_id)) {
			return false;
		}

		$transactions = elgg_get_entities_from_metadata([
			'types' => 'object',
			//'subtypes' => self::SUBTYPE,
			'metadata_name_value_pairs' => [
				'name' => 'transaction_id',
				'value' => $transaction_id,
			],
			'limit' => 1,
			'order_by' => 'e.time_created ASC',
		]);

		return $transactions ? $transactions[0] : false;
	}

	/**
	 * {@inheritdoc}
	 */
	public function setStatus($status, array $params = []) {
		$params['entity'] = $this;
		if (elgg_trigger_plugin_hook("transaction:$status", 'payments', $params, true)) {
			$this->status = $status;
		}
		return $this;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getStatus() {
		return $this->status;
	}

	/**
	 * {@inheritdoc}
	 */
	public function setOrder(OrderInterface $order) {
		$this->setSerializedMetadata('order', $order);
		$this->setMerchant($order->getMerchant());
		$this->setCustomer($order->getCustomer());
		$this->setAmount($order->getTotalAmount());
	}

	/**
	 * {@inheritdoc}
	 */
	public function getOrder() {
		$order = $this->getUnserializedMetadata('order');
		if ($order instanceof OrderInterface) {
			return $order;
		}

		elgg_log("
				Order information for transaction $this->guid is corrupted:
				$this->order
			", 'ERROR');
		
		return false;
	}

	/**
	 * {@inheritdoc}
	 */
	public function addPayment(PaymentInterface $payment) {
		$payments = (array) $this->payments;
		$payments[] = serialize($payment);
		$this->setSerializedMetadata('payments', $payments);
		return $this;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getPayments() {
		$payments = (array) $this->getUnserializedMetadata('payments');
		foreach ($payments as &$child) {
			$child = unserialize($child);
		}
		return $payments;
	}

	/**
	 * {@inheritdoc}
	 */
	public function refund() {
		$params = ['entity' => $this];
		return elgg_trigger_plugin_hook('refund', 'payments', $params, false);
	}

	/**
	 * {@inheritdoc}
	 */
	public function setCustomer(ElggEntity $customer = null) {
		$this->customer = $customer;
		return $this;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getCustomer() {
		if ($this->getOrder()) {
			return $this->getOrder()->getCustomer();
		}
		if (isset($this->customer)) {
			return $this->customer;
		}

		$customer = elgg_get_entities_from_relationship([
			'relationship' => 'customer',
			'relationship_guid' => $this->guid,
			'inverse_relationship' => true,
			'limit' => 1,
		]);
		if (!$customer) {
			return false;
		}
		$this->customer = array_shift($customer);
		return $this->customer;
	}

	/**
	 * {@inheritdoc}
	 */
	public function setMerchant(ElggEntity $merchant) {
		$this->merchant = $merchant;
		return $this;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getMerchant() {
		if ($this->getOrder()) {
			return $this->getOrder()->getMerchant();
		}
		if (isset($this->merchant)) {
			return $this->merchant;
		}

		$merchant = elgg_get_entities_from_relationship([
			'relationship' => 'merchant',
			'relationship_guid' => $this->guid,
			'inverse_relationship' => true,
			'limit' => 1,
		]);
		if (!$merchant) {
			return false;
		}
		$this->merchant = array_shift($merchant);
		return $this->merchant;
	}

	/**
	 * {@inheritdoc}
	 */
	public function setAmount(Amount $amount) {
		$this->amount = $amount->getAmount();
		$this->currency = $amount->getCurrency();
		return $this;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getAmount() {
		return new Amount($this->amount, $this->currency);
	}

	/**
	 * {@inheritdoc}
	 */
	public function setProcessorFee(Amount $fee) {
		$this->processor_fee = $fee->getAmount();
		return $this;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getProcessorFee() {
		return new Amount((int) $this->processor_fee, $this->currency);
	}

	/**
	 * {@inheritdoc}
	 */
	public function setPaymentMethod($payment_method) {
		$this->payment_method = $payment_method;
		return $this;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getPaymentMethod() {
		return $this->payment_method;
	}

	/**
	 * {@inheritdoc}
	 */
	public function setFundingSource(FundingSourceInterface $funding_source) {
		$this->funding_source = serialize($funding_source);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getFundingSource() {
		if ($this->funding_source) {
			return unserialize($this->funding_source);
		}
		return $this->funding_source;
	}

	/**
	 * {@inheritdoc}
	 */
	public function serialize() {
		return serialize($this->toArray());
	}

	/**
	 * {@inheritdoc}
	 */
	public function unserialize($serialized) {
		$data = unserialize($serialized);

		$this->initializeAttributes();
		if (!$data['_id'] || !$this->load($data['_id'])) {
			if ($data['_order'] instanceof OrderInterface) {
				$this->setOrder($data['_order']);
			}
			$this->payment_method = $data['_payment_method'];
			$this->status = $data['_status'];
			$this->amount = $data['_amount']->getAmount();
			$this->processor_fee = $data['_processor_fee']->getAmount();
			$this->currency = $data['_amount']->getCurrency();
			$this->time_created = $data['_time_created'];
		}

		if ($data['_merchant']) {
			$merchant = get_entity($data['_merchant']['_id']);
			if ($merchant) {
				$this->setMerchant($merchant);
			}
		}

		if ($data['_customer']) {
			$customer = get_entity($data['_customer']['_id']);
			if ($customer) {
				$this->setCustomer($customer);
			}
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function toArray() {
		$export = (array) $this->toObject();
		$export['_id'] = $this->getId();
		$export['_time_created'] = $this->time_created;
		$export['_order'] = $this->getOrder();
		$export['_amount'] = $this->getAmount();
		$export['_processor_fee'] = $this->getProcessorFee();
		$export['_payment_method'] = $this->getPaymentMethod();
		$export['_status'] = $this->getStatus();
		$export['_merchant'] = $this->getMerchant();
		$export['_customer'] = $this->getCustomer();
		return $this->prepareExport($export);
	}

	/**
	 * Export entities
	 *
	 * @param mixed $val Value to export
	 * @return mixed
	 */
	protected function prepareExport($val) {
		if (is_array($val)) {
			foreach ($val as &$elem) {
				$elem = $this->prepareExport($elem);
			}
		} else if ($val instanceof ElggEntity) {
			$export = (array) $val->toObject();
			if (isset($export['description'])) {
				$export['description'] = elgg_get_excerpt($export['description'], 1000);
			}
			$export['_id'] = $val->guid;
			$val = $export;
		}

		return $val;
	}

	/**
	 * @deprecated 2.0
	 */
	public function getDetails($name = null) {
		if (!isset($this->details)) {
			$details = $this->getMetadata('details');
			if ($details) {
				$this->details = json_decode($details, true);
			} else {
				$this->details = [];
			}
		}
		if (!$name) {
			return $this->details;
		}
		return elgg_extract($name, $this->details);
	}

	/**
	 * @deprecated 2.0
	 */
	public function setDetails($name, $value = null) {
		$details = $this->getDetails();
		$details[$name] = $value;
		$this->details = $details;
		$this->setMetadata('details', json_encode($details));
	}

}
