<?php

include_once __DIR__ . '/../controllers/checkingController.php';

class PaymentModel{
    private $payment;
    
    public function __construct(){
        $this->payment = new PaymentController();
    }
    public function addPayment(){
        $message = '';

        if($_SERVER['REQUEST_METHOD'] === 'POST' && 
        isset($_POST['paymentId'], 
        $_POST['reservationId'], 
        $_POST['customerName'],
        $_POST['paymentMethod'], 
        $_POST['amount'],
        $_POST['paymentReferenceNumber'], 
        $_POST['paymentDate'],
        $_POST['paymentStatus'])){

        $paymentId = $_POST['paymentId'];
        $reservationId = $_POST['reservationId'];
        $customerName = $_POST['customerName'];
        $paymentMethod = $_POST['paymentMethod'];
        $amount = $_POST['amount'];
        $paymentReferenceNumber = $_POST['paymentReferenceNumber'];
        $paymentDate = $_POST['paymentDate'];
        $paymentStatus = $_POST['paymentStatus'];

        if($this->payment->addPayment($reservationId, $customerName, $paymentMethod, $amount, $paymentReferenceNumber, $paymentDate, $paymentStatus)){
            $message = "Payment Added Successfully!";
        }else{
            $message = "Error!Payment Added Failed!";
        }
        
        }
        return $message;

    }
            public function getAllPayment(){
                return $this->payment->getAllPayment();
            
    }
    
}