<?php
Namespace Yellow\Bitcoin;
Interface InvoiceInterface
{
    public function createInvoice($payload = array());

    public function checkInvoiceStatus($id);
}