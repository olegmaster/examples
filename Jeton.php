<?php

namespace app\controllers\payments;

use app\core\MY_Controller;

class Jeton extends MY_Controller
{
    protected $parameters = [];

    public function __construct()
    {
        parent::__construct();

        $input_method = $this->input->method(true);
        switch ($input_method) {
            case 'POST':
                $this->parameters = file_get_contents('php://input');
                if (empty($this->parameters)) {
                    $this->send_response(400, 'Missing body');
                }
                $this->parameters = json_decode($this->parameters, true);
                break;
            default:
                $this->send_response(405, 'Only post');
        }

        $merchant = $this->uri->segment(4) ?: 'klas';
        if (is_null($merchant) || empty($merchant) || !in_array($merchant, \ConfigManager::getMerchantsSiteList())) {
            return $this->send_response(400, "Merchant not found");
        }
        $this->db = $this->load->database($merchant, true);

        \LogManager::payment_callback_logger(__CLASS__);
    }

    public function payin_callback()
    {
        $this->callback('deposit_jeton_m');
    }

    public function payout_callback()
    {
        $this->callback('draw_jeton_m');
    }

    private function callback($transaction_model)
    {
        $this->load->model([
            $transaction_model,
            'balance_change_m',
        ]);

        $required_fields = [
            'paymentId',
            'orderId',
            'customer',
            'status',
        ];

        foreach ($required_fields as $field) {
            if (!array_key_exists($field, $this->parameters)) {
                return $this->send_response(400, "Missed required field: {$field}");
            }
            if ($field === 'amount' && (float)$this->parameters[$field] <= 0) {
                return $this->send_response(400, "Invalid amount");
            }
        }

        $transaction = $this->$transaction_model->get_by([
            'order_id' => $this->parameters['orderId'],
        ], true);

        if (!$transaction) {
            return $this->send_response(400, "Transaction not found");
        } else if ($transaction->jeton_transaction_id != $this->parameters['paymentId']) {
            return $this->send_response(400, "Payment not found");
        } else if ($transaction->approve_status == 2 || $transaction->approve_status == 3) {
            return $this->send_response(400, "Transaction has been processed already");
        }

        $member_id = $transaction->m_id;
        $amount = $transaction->amount;
        $transaction_status = 1;
        $approve_status = 0;
        $notification = 'Unknown error while processing Jeton wallet transaction.';

        $this->db->trans_begin();

        if ($this->parameters['status'] == 'SUCCESS') {
            $transaction_status = 2;
            if ($transaction_model == 'deposit_jeton_m') {
                $approve_status = 1;
                $notification = 'Jeton deposit has approved successfuly.';
                $direction = '+';
                $ttc = \Constant::ttc_jeton_deposit;

                //top-up balance for deposit
                \MemberManager::updateMemberBalance($member_id, (float)$amount, $direction);
            } else {
                $approve_status = 2;
                $direction = '-';
                $notification = 'Jeton withdraw has approved successfuly.';
                $ttc = \Constant::ttc_jeton_draw;
            }

            //update balance change in any case
            $this->balance_change_m->save([
                'm_id'                  => $member_id,
                'direction'             => $direction,
                'change_amount'         => (float)$amount,
                'balance_amount'        => (float)\MemberManager::getMemberBalance($member_id),
                'transaction_type_code' => $ttc,
            ]);
        } else if ($this->parameters['status'] == 'FAILED') {
            if ($transaction_model == 'deposit_jeton_m') {
                $approve_status = 2;
                $notification = 'Jeton deposit have failed.';
            } else {
                $approve_status = 3;
                $notification = 'Jeton withdraw have failed.';

                //rollback withdraw request amount to member wallet
                \MemberManager::updateMemberBalance($member_id, $amount);
                $this->balance_change_m->save([
                    'm_id'                  => $member_id,
                    'direction'             => '+',
                    'change_amount'         => (float)$amount,
                    'balance_amount'        => (float)\MemberManager::getMemberBalance($member_id),
                    'transaction_type_code' => \Constant::ttc_jeton_withdraw_cancel,
                ]);
            }
        }

        $transaction_updated = $this->$transaction_model->save([
            'transaction_status' => $transaction_status,
            'approve_status'     => $approve_status,
        ], $transaction->id);

        if (!$transaction_updated) {
            $this->db->trans_rollback();
            return $this->send_response(400, "System Error");
        }
        $this->db->trans_commit();

        \MemberManager::setNotification($transaction->m_id, $notification);

        return $this->send_response(200, "Data is updated");
    }

    private function send_response($status = 200, $message = "")
    {
        $this->response['message'] = $message;
        $this->output->set_status_header($status)
            ->set_content_type('application/json', 'utf-8')
            ->set_output(json_encode($this->response))
            ->_display();
        exit();
    }

}
