<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebsiteEmailSetting extends Model
{
    public const DEFAULT_SUBJECT = 'Vos documents de réservation — commande #{{order_number}}';

    public const DEFAULT_BODY = "Bonjour {{customer_name}},\n\nVeuillez trouver ci-joint vos documents de réservation pour votre demande de visa.\n\nNous vous remercions pour votre confiance.";

    public const DEFAULT_SIGNATURE = '{{website_name}}';

    protected $guarded = [];
}
