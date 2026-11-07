<?php
declare(strict_types=1);
/** Weakened copies judged by section G (5.18.104, root docs/73 §H.4.16): the gateway and its Evolution driver. TEST ONLY. */
$GW = 'lib/EvolutionOfferGateway.php';
$claim = "                \$echo = \$this->guard()->claim(\$id, \$this->evo->instanceFor(\$m->channel), \$m->echoEvent) ? 'claimed' : 'late';";
$M = array_merge($M, [
    ['label' => 'OM35 the echo claim moved after the settle and the store', 'edits' => [
        [$GW, $claim, "                \$echo = 'claimed';"],
        ['lib/OfferSender.php', "            } catch (\\Throwable \$e) { /* the customer has it; bookkeeping changes no outcome */ }\n        }\n        return 'turn';",
         "            } catch (\\Throwable \$e) { /* the customer has it; bookkeeping changes no outcome */ }\n            (new \\EvoWebhookGuard(\$pdo, \$f))->claim(\$res->messageId, '', 'offer.send');\n        }\n        return 'turn';"]],
     'sections' => 'G', 'cases' => 'G02', 'pattern' => '/G02 /'],
    ['label' => 'OM36 the echo claim removed', 'file' => $GW, 'old' => $claim, 'new' => "                \$echo = 'claimed';",
     'sections' => 'G', 'cases' => 'G0[12]', 'pattern' => '/G0[12] /'],
    ['label' => 'OM47 stored as DishNet, so the AI drops the customer\'s next message', 'file' => 'lib/OfferSender.php',
     'old' => "'agent_name' => 'DishNet Plugin', 'event_key' => 'wa_offer'", 'new' => "'agent_name' => 'DishNet', 'event_key' => 'wa_offer'",
     'sections' => 'F,G', 'cases' => 'F01|G02', 'pattern' => '/(F01|G02) /'],
    ['label' => 'OM51 NotificationService::sendImage used for the image', 'file' => $GW,
     'old' => "                \$res = \$this->evo->sendMedia(\$m->channel, \$m->phone, 'image', (string)\$m->image['payload'], \$m->text, '', \$m->class);",
     'new' => "                require_once __DIR__ . '/NotificationService.php'; (new \\NotificationService(\\SqliteStore::create((string)(\$this->config['_data_dir'] ?? sys_get_temp_dir())), \$this->config))->sendImage('support', \$m->phone, 'https://example.invalid/offer.png', \$m->text, 'wa_offer'); \$res = ['ok' => true];",
     'sections' => 'G', 'cases' => 'G01', 'pattern' => '/G01 /'],
    ['label' => 'OM78 a late echo recorded as claimed', 'file' => $GW, 'old' => "\$m->echoEvent) ? 'claimed' : 'late';", 'new' => "\$m->echoEvent) ? 'claimed' : 'claimed';",
     'sections' => 'G', 'cases' => 'G03', 'pattern' => '/G03 /'],
    ['label' => 'OM-G1 a code carrying the transport text', 'file' => $GW,
     'old' => "        return 'send_failed';\n    }", 'new' => "        return \$e !== '' ? \$e : 'send_failed';\n    }",
     'sections' => 'G', 'cases' => 'G04', 'pattern' => '/G04 /'],
    ['label' => 'OM-G2 ok with no id counted SENT', 'file' => $GW,
     'old' => ": new OfferSendResult(OfferSendResult::MAYBE_SENT, 'no_message_id', '', false, \$echo);", 'new' => ": new OfferSendResult(OfferSendResult::SENT, '', '', false, \$echo);",
     'sections' => 'G', 'cases' => 'G04', 'pattern' => '/G04 /'],
    ['label' => 'OM-G3 a may-have-been-sent read as certainly not sent', 'file' => $GW,
     'old' => "        if (\\EvolutionApiService::mayHaveBeenSent(\$res)) {", 'new' => "        if (false) {",
     'sections' => 'G', 'cases' => 'G04', 'pattern' => '/G04 /'],
]);
