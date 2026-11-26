#!/usr/bin/env bash
#
# values-5.18.107.sh — READ-ONLY: the production values 5.18.107's build needs (root docs/76 §N), 25 Nov 2026.
#
# It changes nothing — no table, no row, no setting, no file in the plugin or its data folder, nothing in uCRM or
# Evolution. The plugin's jobs hold the book only for moments, so the reader waits up to 70 s for one to hold it and
# reads beside it, creating nothing; if none comes, or one closes it at the instant it is opened, the open creates
# SQLite's own empty -wal/-shm beside the book, as every plugin process does, and it says so (exit 3; the next plugin
# connection uses and removes them). It prints COUNTS, NAMES OF PRODUCTS AND PRICES, AND SHAPES ONLY: no phone number, no person's name, no message
# text, no key or token; a text setting is "set, its size, digit counts" (a hash prefix only for the long settings text);
# a number only its form; an invoice number only its shape (<6d>). As root on the host:
#
#   1. it finds the uCRM container, the installed plugin, its data folder and the book's owner (the report wrappers'
#      discovery), refuses a root-owned book, and checks that the installed plugin is 5.18.106 (bf29cbd) and that every
#      file of its lib/, both profiles and the shop catalogue (299) are 5.18.106's bytes — hashed by the container's php
#      as the book's owner, after a positive control; a missing file, a file it cannot read and a file that differs are
#      each reported as such;
#   2. it checks scripts/values-5.18.107.php beside it against the sha256 this file pins, and feeds it on stdin to php
#      inside the container, as the book's owner — nothing is copied into the container;
#   3. the reader (see its header) opens the book read-only (waiting up to 70 s for a plugin process to hold it), asks uCRM with
#      GET only, and prints the values with every run of nine or more digits withheld; this file masks again;
#   4. it prints the container's PATH (W.8: the harness fix needs it), then where the log is.
#
# The one command (it writes its own log, mode 600, under /root/dnb-sales):
#
#   cd /opt/dishnet && git pull origin claude/study-this-jhe2eg && bash scripts/values-5.18.107.sh
#
# Then send back the LOG FILE with the `cat` line it prints at the end — never a copy of the terminal.
# There is no rollback, because nothing changes.
set -uo pipefail
umask 077

PLUGIN="dishnet-hybrid-sudan"
CONTAINER="${UCRM_CONTAINER:-ucrm}"
IN_CONTAINER="${IN_CONTAINER:-/data/ucrm/data/plugins/$PLUGIN}"
PLUGINS_IN="$(dirname "$IN_CONTAINER")"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
READER="$HERE/values-5.18.107.php"
READER_SHA256="0a25ad287a4dfa41c73fad8e4bcbb07dc706bb1d39a75561cb2ee28659eb8cb1"
LIVE_COMMIT_FULL="bf29cbd7171639f93e674ab174a6d09948b68932"   # 5.18.106 (release), live since 22 Nov 05:48Z
# every file of lib/, both profiles and the shop catalogue at 5.18.106 (git ls-tree; 299) — not only the 20 the reader names:
# those load others at the top level, and the code they call loads more
LIVE_SUMS="$(cat <<'SUMS'
028e2c41342692f61d42a75fb1d28baf18818a3d58ff59d3e4b09a059f725e00  assets/shop/catalogue.json
bfc84bd2884680701e1709e284d84cb6fc77fcdc7824ba8dc43739b403ebed98  lib/AiLeadService.php
22cd71a6360a417a31caa324cf7dae0ad615de5652c91c41e0fa22bdd0939d3b  lib/AiMinimalContext.php
dc9c111cee3357b810049e109586cb7beb914ff2977077014ca7f7597c2fd971  lib/AiSecurityPolicy.php
2d8177eb8834f585e94b93671a5358e504ca90f0ccb3b022594185e1953579bf  lib/AlertService.php
8ea63d3ec68f9670dbdf5125b17f932aa5feaaa90d5258184afa4985c71de142  lib/ArchiveService.php
a87fd313ef7917b3e51da33c505a8f3cda3969ef23d7df1707db0689b94cfbab  lib/AutomationPolicy.php
7ee1057201aface635e4446660b020c14e042921ad615f02ad9e6d2f3cae35a4  lib/BankGate.php
96c5d03874be4e8890d0f3e1ad11aeb72a2cccc8a626387b7fcbc9485d9915d5  lib/BankImport.php
899adb304d77bbee6e6ed6e855ebc9363741c052606ade62010d9e334e069c8a  lib/BankStatement.php
5345be83639e692fec7324bef612f1f9bfe286d35891f61b4797205fe333b7ff  lib/BankStatementStore.php
5b0615763e8c1f97b547d732f4d5159d1f40b635e5186f9052bcd7c5da7a81db  lib/BlueCardDb.php
91b6aab2a55bedf4de04069d634bfc46cf8105cda7b40107c10e5b29ff388e29  lib/BlueCardDumpParser.php
cab6ab00adda515905c87c0df75fd4550c9f6e49afe04fdbba0961a2502fe197  lib/BrainContext.php
5730cb97c5f445b86a25c0a9b7aed756fb9fe366ef75733f453b7268b8c8655f  lib/CanonicalHost.php
3b03f357b4662d7d05ba3f2c90d6027f572efbd3d7bb9dbcf01064370e78f288  lib/CashbookNonCashView.php
ce3f5762de3fff6dc57c75a3238ef865ca757ac188ce7fd0572058e79a146e0e  lib/CashbookSeeds.php
347fbaeea0e9234a431d593a8d8cbacc258584669ebbcfab6ea5493eb2eeb245  lib/CashbookService.php
372a568a1acc6144125dd37df2bc65665db1f793e6ed7de5a4b65e9a77773fa0  lib/ChannelContext.php
31c29e51bdf98fd4916c2144919757b60569b3b7a4d2ac2c535d46bf73f3d316  lib/ChannelRegistry.php
9c6cc6a1e3058e6d0c37d90f1848fdaf9c09b4ad40177d88e8b731e799df9b4c  lib/ClaudeWaClient.php
3043f72aa2a6a935d1b938a8a8ff24523f86b30c51bd2e2510ed6646d9c01aed  lib/ClientInvoiceCacheRefresher.php
11b314768d277b2c2aee756a3b5faacd207a36f263fc9878fbe9a0d52e90119e  lib/ClientSearchIndex.php
d367466ad34f30b4eb8f1d5fb30e80b4fd8d241089b3a978ef5efabe6db3cbd0  lib/ColleagueNumbers.php
bf7e1b1f1f5e132e0ad3af96ad20f5fcb011307cb83d14ee504abcc4ee581da7  lib/ConfigVault.php
77b9981d8eefd10bf1a11f4d889f5cde576bb454ac3fb73a7c84fa7b6d54c599  lib/ContactOptOut.php
79d8a9909b0d43007426c8ead8fe21092b89f5d403fc596629343cd05c0321e8  lib/ConversationService.php
153aebea2bff4d9ed13eafa99d444f79e1f32a0a7d7e726cb564d11ae755350a  lib/CrmApiClient.php
981e727e6da0f6f868f219f1e6bdff192e775737f64b4b94f6e1e76fb76bd3be  lib/CrmKitAttribute.php
e6a63281dc558c4c5fc762aa7d143a4b724bf88a1f2d1546365c9539cfc287fe  lib/CrmQueue.php
df58a9f4d3ae246a4cff72f0cd9627ba9e30f7fbf2ec3bbcec2c20fb22ce0f67  lib/CustomerAccountService.php
345849c4dc6a04581accfb8b7a346db5394c16743d288d5738f7430d00fcb66e  lib/CustomerContact.php
990618e638c9c59d67091b8a0632612d5e2152ab5379d269db15a5804dd67ce6  lib/CustomerDataTools.php
b1088b39b75f312b47793cb5494ac3898b11d55ea43a343b23fc8c955e5d3dfa  lib/CustomerEmailDispatcher.php
26ba5e48fa420a186be11734047d3d6ddfaf34b03b536bb5384a16a8ef7dc59a  lib/CustomerEmails.php
65c98ebffa06bda8d383944f6919635e068bf17a06c8e75025f8d429d573939c  lib/CustomerIdentity.php
19a9ac25d6f8c8d88b9fd9d5a36b1a148ef370b59b4b55afab468b292d643b23  lib/CustomerIdentityService.php
b06f9ddc0fbc65dfd856a3fe21948b13a35bc58c4d3b0107664e1dfdfc7e5618  lib/CustomerJwtKeys.php
8bfde8f718103a7cb86262bf7536deb143205d0338a7b939b2699f9d28afa6ea  lib/CustomerSession.php
307494c6b5ef41d925a7a1e141c64c76bef5ba63899a1dd5a3a2588ad2fd2245  lib/DailyReportService.php
b96e7a01b6816ee0a3a847ab2570508675c1f15433ce660a766ce340f95fb07c  lib/DeliveryPdfService.php
5b01721ec0acd9988cd235d79266bff2eb3c1a3da5646cda3e033ec75fcdc09f  lib/DishNetAiBrain.php
704d3ebc89249b7ec727c020d1ab5bc8d5556e855b308921d83e26d7f3f2c7e0  lib/DishNetTestSuite.php
d8deb95ac3a6e09bf807a8b36dad065d3db1ad400c32b445b54f3e200a7384cd  lib/DishNetTools.php
bdc106ab09adc2911ddf2f22bde056e08fb41edff037363dd895ddc79d88d682  lib/DistributorApplicationService.php
359257b7ce73dab1e5a8deb35ca3e87be530c623e25682f63f3836d10e81e7e2  lib/DistributorAttribution.php
682a125e6b9f947b0aa1e61e7e779c4a1ac009a1c37f4b6d40ca2098ee05aabc  lib/DistributorEvents.php
895455c8924675c2753d9d208d47cfbdf1a597fa0702a77300e7082d1900a617  lib/DistributorNotifier.php
0f194bc8cf6ef4f3155a8b7858d6718783f23c39b684823278436711a9adf87e  lib/DistributorRegistry.php
538d373e946a118aa3b6caa42d50d7172f7fe1fa6aca5b7583c4ae823aed8ba4  lib/DpoBootstrap.php
74457ca86ba7b7402dd45fd7f4d304353d7abddf04af89fc1ceaab584700ee26  lib/DpoClient.php
a3513eb5afc7dd784bffb4dacf1f1523866a6af560a31ce35287ce74b5946721  lib/DpoPaymentService.php
71533c50580a4c8961d29ca7266fa145629cd3f16dcbe4e97e3ece0a415ad558  lib/DpoPaymentStore.php
8be4359131368aaea250628822c266f14357c4968ae41b085734d437b94a956f  lib/DrSnapshot.php
d171c271955ac7c7e6d4af1fc8373c797de6668f844720062256abf9d66c749a  lib/DraftMailCopy.php
d460e6b8669f1974102662a0076e548cdf12db9903577ce94a5da036f772e154  lib/DualReadCashPosition.php
7a7da0fb3bf37323d463a7fa73e521808b6d2cafb017bb67bb3d418c78165923  lib/EcobankDetailsCsv.php
fd68d73c4249fa85880972ef85bb0fd4478349cce9e3ed6aeca0c707b851a175  lib/EfrisClient.php
2ad3080045f94839b2c2e35a1d39f01cd8f4679b987dd4b799b200d873a75f94  lib/EfrisClientField.php
cca2013758e7a8e037e7d08730e5a83f297475d8454b4ad47fdd92c005478cad  lib/EfrisGoodsService.php
7104d2c72cc767f9e022fc552d8315c29d680f17df7c328b7a2c7ac45096b770  lib/EfrisGoodsStore.php
61b95226dde1226405f6a0113d7aa5c5e3eedba816ab7747cfbb895398bf6591  lib/EfrisInvoiceMapper.php
16c0b36b9b52e96cda3b008e6bdd0ae4c1a6b018f86108a65ef98630690bf7d0  lib/EfrisInvoicePdf.php
79c1cbbed236ad0fe9433bce07714d3b8d28846fa18b426bc8752c87abbf746f  lib/EfrisService.php
a7681776ea2110830947357a8eeededb1130b08df14034674c2a7848d29f5079  lib/EfrisStore.php
7a82ba51fdbde8139ce3d377271945ed0b5a4348a1c79c1736b8c6a9fd745b26  lib/EmailCustomerMatcher.php
44b477a2702a140ec221ed3094d44ddee5045cfa84f0c248a3385d07c7ca40b8  lib/EmailDraftStore.php
ef2884f36f1985957a766ccf100979c58637bf1f79d87d4fe9ea3f3583f9147e  lib/EmailIntentClassifier.php
47a63eba29df32cdde3985a7b02cbf1dd3912dad673bd00df30020fceacd32c6  lib/EmailRecipients.php
fe9886cdacf532e234894898612b1848e7269e6e3d3bc00674747ae83e4f01ff  lib/EmailReplyPolicy.php
ca017d027f65605d732a8d9bdeae2699cdb27527bda1018bb3d5f4eb9634b8c6  lib/EmailSettingsWriter.php
e2c38d3798bca5e71b4448c8b7261a9d0d2f6a525ab83fab32029f7f09e3f932  lib/EmailTemplate.php
c2db0a4fe25126d13bb024bc517a54b331ed4b25fe6672318b031e24fc0a5c4e  lib/EquipmentAssignment.php
a9a7a2796ab1e88ea96fc497a09439ebfbe1e4927ea072ab5f4e6ea6a2e91f23  lib/EventBus.php
67de4296051c7d5fbcff420cb4b44978675bf74e37bff7d6641c86225c8b4b3b  lib/EvoWebhookGuard.php
89372cd3161a7ae9ae4fe28a3130429a7106bba7e8791cc31b11dceb99330035  lib/EvolutionApiClient.php
2c44830f6221e1385abfb9805485985e5a0ef9e914b2b809f459eb88a44501dc  lib/EvolutionApiService.php
ee4f308b3739e60a04535052f789dc989e8cd3f0f172eeec4799b12c2766d178  lib/EvolutionOfferGateway.php
86bf8f903761c01eaf834165de821991d4d7a3238b9ba28e2530ab6417e9c7a3  lib/ExpenseAdvanceService.php
35d77078542b9c3e70628e4d66ab4e3244109a2ddcbbe56ee3d13dca7fbf4bfe  lib/ExpenseGateway.php
2ccc3b69ea5624081eed2a05d23aa9e90595c7c694aceb0b380f1f8fa585580d  lib/FcmPush.php
fabbb18f47c012a370e7d0422bc2a1648fb1b5924035957551e11e9d4e93b38e  lib/FiberFinanceEngine.php
9c148b2d4411712fd05947af18cb0158ee551863aacbf069c6e004aaa7094d11  lib/FiberInstallService.php
89bc5ac12362d5300a71631bde24d4445f0d630a5ad073c9b8aaf723bfe88960  lib/FiberPurchaseService.php
bd2a47be767b47187bbe6526dabf7fc9ceaa3d501210b35199ac0553c8808fa2  lib/FieldAgentService.php
4cc9c41b20a7c675471299c239bcf30670d0c43d0d8e2e4ab05ac3bd966b8ba2  lib/FinAudit.php
840df3dbdfba3e8c02009ce87c38a5eec028a2d42a91534b6cbc25a8682bb00c  lib/FlyerAsset.php
ef86afcdb87024818365da8884be097b3965cd0fba9c748863b514909c67575f  lib/FollowUpEvaluator.php
c4b8c514a681c343ddd3b233051d7e156201facc8b1453ef436fee8ecc0c6312  lib/FollowUpPolicy.php
d9387cd34dc6b2797280529434e739427014f9c54de6dfd665a252b65673c63b  lib/FollowUpService.php
08e7b55ce4c7d787ea45d0d6c3adbf9897eb30799cffb74042038dcbb79cb980  lib/FtthCrmService.php
2462573a352c5284aa8171ee6fb062220886d278f0216656edab6fcdf07a207d  lib/GoogleDriveBackup.php
ec40cc4fcaf959e62937b4247c22e0af1f90ead407e3111f09d77fed92ca4852  lib/GptWaClient.php
060ac162defe0a0717d5e086acfcd3b324447dd203f7a386699d4849f558ce8a  lib/Handover.php
c3550c5ac1ba9168041319b2177b5b1f17068d280694ed739e38f689c705de67  lib/HardwareKnowledge.php
dc6fe8e4c7a765f2201b733cb9316c5fb3f4863412b30edebc356652b6876e0b  lib/HrmService.php
4b892738c6109009de0b2ff12aded4541bd07ddf44ebf50c6bb88ee0e9f8e4b9  lib/HumanTakeover.php
b710dfaf77df30ecf91a04a26a8b997e368895ca422b8ac4729e0e60980e22b3  lib/IdempotencyGuard.php
47baed7377b62f91dea9c8e8f61e4020d5fc86e44ab4352ebf17e1bd936e59c3  lib/ImageCompressor.php
03fd813c61c9d34b30668cbca326b3198f09cd99418ff4683508aef67ac78bb0  lib/InboundMailFilter.php
9b0068469b1ff94fe5f9f02498981abffb5ac50f1afcacdf132531f9b9a693d7  lib/InboundMailWorker.php
6546389eb6ea38e48e518d904015a417162e59edb2b9dffe3a33c15da12f458e  lib/InboundMedia.php
13c34668b98b5cfed7fd1f997143e00d581cfe1d70da399701e0535420a2062f  lib/InboxReplyRoute.php
618a837bbafe044226ae689a2b1ec4713301e68c1f49860f810dc5d5c4ffcbf4  lib/InstallAuth.php
c8694fa57d2bc74fe6f263227186f4a8b5b1465981f4f34f9133115c0297fed4  lib/InstallAuthEmails.php
035bbf4c054e5d0d89b5196a940f1ac65d65b0045b318578558a51ee087c934b  lib/InstallAuthNotifier.php
0cd057cecc0e41e0510f52d971653dc28326ffaf20f60d76a0d344044aad97d6  lib/InstallScheduledWhatsApp.php
eed0550c0cf7338128b8ac449e30b1a78660181b88fa4204f0697290290473bc  lib/InstallationTerms.php
0b96b525e1f13de3fb0f587d6020c7a284e45e184854352726f94f80aa209dec  lib/InternalAuth.php
e2a0ede8e0ef6c89823770e017dc7c4d6101ac00aa3693f3e89d83b07c3eb232  lib/InternalNumbers.php
c84abc3478eae8f6bff05af592172d4b97ef127382f2949ccd490ce9b7f3da50  lib/InvoiceReminders.php
a4a5608ecbae027ca108ff42175282652f9665bc07a9d01c851132db7759f7ba  lib/InvoiceTotals.php
227e348e483712a315697136804ac3b41bdb3eb562ee18a8d09e65ab26506337  lib/JmapMailbox.php
0b7ccb6f89a5de1ab2561f65dcba4abd7ee3a82989a3a5120cb1f43db53dd9b3  lib/JobAccess.php
ce23b476529143233d6004438aa6c5f92173c57372c6704b54b0bc571370b56d  lib/JobBudget.php
0f9ce0aa247225d885ffb1cd0d2388b1602f9c9012fd9ae8737ae5fc81333785  lib/JobMessages.php
3ef05c7ba164cc6a8a3a091aa26ea37eb98225294c41a196908bb0c55f0b916f  lib/JobNotifier.php
d41ebf86730a09b6c63b2629b42c27614d8ecde269080ecd64252093463739dc  lib/JobPhotos.php
4105b041112ae4ec6754c92f7317b97721d65f30b8f6c3ecd9de6a3e9fffbf54  lib/JobReturn.php
4ee1c19289fc07aa565ae6ddf55c23a30cc7663f2c4f5e23168509baaac032c1  lib/JobTime.php
d5b8da42fe734ed64e70c7f8ddd746718c8849680e920beb42d6e4ca86dbac74  lib/JobWindow.php
d1d979f438e1ed0f2a861fd426bedeae5d87b8c1159c7c7058a1d665995c2443  lib/JsonStore.php
13d613622525e6130ee710cc4aac6556b2a61e530bb20e25ec38bcb98d491207  lib/JwtAuth.php
c8d59ee46255d4b9687bbf92a3b7536ab1bf295c339f3ca601347c0c98e6da6f  lib/KitAttributeIntake.php
a254de5735e0b6dfcbfc1b63409e2cb4a582cdbe9195cacc66cfc1a26d6f5fc8  lib/KitSlMap.php
74fe9cd4fe028d6e4a56a7e567fb1a080af28abe657dbff5e03894a4e6af44fc  lib/KitTaxNote.php
797a024d4a31f73573f4baf947a8a9c0c88c74e0cbaa467e8c5c2d1e44ffff73  lib/KitUsage.php
b1a043e1667b9a21f51a9e0e7a034c801b1f838ceeebff9f22aef25f16948ab4  lib/KnowledgeBase.php
4ac671990da0af6dc1484c4709add94a002619caec0e89d2cfda81faca72d7e8  lib/KnowledgeSeeder.php
ed8dcb1ef902362e267078abbe4284c9b303ee643bfac6840e3accb123f7539d  lib/KycCrmSync.php
6cca6656f518bb3dd6429c1c600daa3698258c24e6226aace55c73a5b417731b  lib/KycFunnelService.php
c6c86311f9532725f90429c618b660493cf439b134e3014cdd710e55ed268349  lib/KycService.php
a68f7f5c21521794fe9bbdb9dd7b6c190be806666f046309f3f784bd9a6001bf  lib/LeadMatcher.php
c3e9d7c5772a0ce7a3ef9d4153e68513ae85fe4977b7e62a70aa350276fa7aa6  lib/LeadRecoveryService.php
25b0a19c35a95e4ed974d480d583938685b0e5262d77c1ebb87154703a7dd5b8  lib/LeadVisibility.php
4ec195f32cf73234c9acb83ddf07c80fe2ebfce046c795ddcc4320300f96beba  lib/LeaveService.php
011fd3eb2318b89bf42106d9f9f4d314e99726b2244d017eef5a7b95e837ddad  lib/LegalContent.php
f3eae1ef21d221d6d76fb95a824aa049d09924809af0fea63b9037b843c4e75b  lib/LifecycleService.php
c8632b24e7b4a8ab8008d7080a8bbf1a60d9f6832515265ac9b0532f81f46f97  lib/LineOwner.php
c8ea38e19bc42082ab2b972d0744f19b5af3b3d55919d5f6876a187f9de7900f  lib/LoginRateLimiter.php
9131faeaad87c6dc413e4985d2f767fbf4af83475e94c92e65209eb4d3956b67  lib/LteCacheService.php
c9874b76bd2ffc4ab11363cc6190733bfe9c1309a9ddef9fa18126e4e369bb89  lib/LteEventService.php
8fe7288845ade4f802e40ecadb0efb6b4bc9f737364e11ef553bd331c366958e  lib/LteService.php
9891d1016a513a58f89cc74338a53fff2146d1c8cb0b8eac2e5ec00fe092f45d  lib/LteSqliteService.php
23c23532fca4c32656c8f85c21d3985dcec89ad2affb178c55095f6f1eea17f5  lib/MagmaApiClient.php
150a2efa1caff10081b963584319cc4c6475ea1b52eca4749de71cb77d4003ff  lib/MailProviderInterface.php
92af49d79b6542825f9bec64cc3b6fe1eb16cb7eb88e705d424a2d6bf91231f6  lib/MailService.php
3c0572483f6196964b7e9faedd7529ce1506abb5a69566041df87c6ac11b185f  lib/MediaBlob.php
9dc18458b00d82d01cf88545c68c3e9e9a51686e2d7f2bd30f472176e6a39084  lib/MediaFetcher.php
868f0c18d970c47effc417de3922c25214a04a1de88e3cf30e98966ed64e0f9a  lib/MediaLibrary.php
ee4bd76c14d3060e9113c8ef332b0c096233af98cb4e27f52b8b211e35ac7c39  lib/MediaPolicy.php
74d98529b2796eb275d931ba5064f5fd1f934b78add1b9ada3263c2b9dd109ac  lib/MigrationRunner.php
b5a195f9aa48120f82bc3b5475710e883199cd126899f5aadcdbbf3daa0d9fe5  lib/NetworkEquipment.php
4584506d16fd609d28a16660a6e34eee90a6551810b3f890a986b285c7c20840  lib/NotificationRetry.php
599a2bca174b2b79dd853ec65e0b909e4f1e098afd18766a6698ad05828d660e  lib/NotificationService.php
2bc14209035c0f859d430f9bfda65cb80e8344e1fafd0cd9d1bc6150f8decd65  lib/NotifyGate.php
24223cfc62b5061893f5cabae05ee36160eb8c60fbf849b0e4e9044b214ea315  lib/NotifyWatchdog.php
b60bbadc5c3a2f209684641a602d5c913a7d028ebe88ad47dd9a6324ff615cd1  lib/OfferAudience.php
9c68e5bbc8c235bba1e6071775b5e8411e443e041658829881bdb12922e5aeea  lib/OfferGateway.php
d61fdc2c78e1044ae301e45459d0d4a6372e49117bcbc15685651e3f9d3bcacc  lib/OfferImage.php
dc51110f4795281d305eefe522f68e83ca6633ff5f7c5ea59f6a28bdc869a1fe  lib/OfferSchema.php
d46eeb82d3d844d7fe0f16d177566f0ff263f6baf3be7ae56cb178f97e60c2b2  lib/OfferSender.php
f21a3d5cb07242248c4e8ff15947add223c1c50830f5ab63cd9c3149d3b4e811  lib/OfferStore.php
9bceccbe9d531eb656a333a2c166db0317e36a9f8004c63bbacc8af19b46a5b6  lib/OfferSwitch.php
fd529c1bc80d726656e343ba3da358fa8e39066949eecd38793550df2b83a1eb  lib/OfferText.php
1a682cf26875cc76fa198e40630d795bfe23776d95a4e66e721010938c509867  lib/OfferWindow.php
ce4741167fb9b80faae41a1f59c401cfa62371984a27d24cbac86ccd38caf127  lib/OpenAiTranscriber.php
94ee82e6f01c5d0317513b80017f14298afdfad1b2c4793c7311daa0dc326456  lib/OtpEmail.php
98602f5ccf1605c4a259ac9a199621dadc8ac72b31f3e4cb0f6c01be54bf2d67  lib/OtpEmailTemplate.php
8297c9bdfddd0d77e807ca5bda370add1d5bffb7144385e64a5030a8ffc51de5  lib/OverdueDunningHelpers.php
9429eda4d4c9ce259c999ed97e078ded8fb63b7c4de8a33f0cea6f016e7a0edd  lib/OverdueWorkbenchService.php
57f55dcef1a78088ab31d729868505521bb8eeef30f483b99fea555aaad2a4a1  lib/OwnedLead.php
474f10859929ff330e2d3cf922d110e6c37333119bf14bfce1587661d0c535d9  lib/OwnedNumberHold.php
740123b7298ab85ca8e8482ba4bc2d4a6116f7687fd54afad5d9d35c8a2deb19  lib/PaymentOptions.php
b6a72895f2c323692cf6833509edfe4b7e077f133da393b5dd0e5576b3100983  lib/PaymentUuids.php
4ba185f7d5908cfc4e77f7f05be3976abfd5fe4b62b5e0d791005ad0b537eeb9  lib/PayrollService.php
4b2e9f06d35beba6265ced2e1e791c8a50a1fe112ff33053b93d91a98bdf8ecb  lib/PdfLinkToken.php
ee36e20115aea190212b62a7b1ef5bbeac965114df4e8bf8474c309b6e5909d2  lib/PhoneNumber.php
d4e116a3bd67b7cd4f87ccec42ff6713708d71234ff6299bc1f1aa2120ba1aed  lib/PlanCatalogue.php
7ba4d5235f6ce43e54aee4d0beafd019e5fa9ee41c34215a5275fadce5c2ca51  lib/PlanFenceGuard.php
500d5643bec2b143b190b9d9c49ddd374a35554a652a9827870fe6991630a635  lib/PluginConfig.php
a0d588ad2057ae4c9a51996a768bad5d15b494aafbb272ce630fe496b0a55d26  lib/PluginLog.php
2108e1905765322c4e296f51e2bb6fb5f19609773f5eaa5a80584cb8e96f297f  lib/PluginQuotePdf.php
885639006a19e6651bf767511a022d7268dd6db52e9a3127d2475d915e5f95d8  lib/PortalLocale.php
ea94c0138646cd10eff16a5bcfa9c90597c4e02673d3d0c9e5fc730c276003e3  lib/PromptRiskSignal.php
5bd83b70a12e2f1448f868aaa777cf2a5a5d78697f2bbd160ebac9eff6f3bd22  lib/PublicPriceFeed.php
13afe506ba1709810c630c2ea79363f74d1a15562853d2557bab128ba03eff9b  lib/PurchaseService.php
2e8999e3a1efd2bca4c78a4dc98e9f349232d133006c30206fbd9e0d7eca89e1  lib/QuotationPrefill.php
281688243bcf7214592ac3e9db1245af286a5153b0bfa40c52f931a226532adc  lib/QuotationService.php
7205f04b2203b94640869420a41d1f6ce1b4ec884dc2a3e73f2ae2b2d5d934e5  lib/QuoteAgentCopy.php
1a1c2bb5c9794324ead81064403468cd5fab1c4219582c88962bd68291cca3dd  lib/QuoteChase.php
3c674a2f57e5262137dfb899212685f198bc3d28e5e6339f6e46b3b203067060  lib/QuoteChasePolicy.php
d913002f2c83fdeeca422839a812b6caaa48adefb427f207e3f311a0f958ae64  lib/QuoteChaseText.php
4f82e8b8a1b9075dae8e670f4642325bff5aedfdc35447baab7025a55ba06990  lib/QuoteDailyList.php
c7f6387757704c3f4690e88225b3460d02d6a188e7e290b9e8c088ed8d6873c7  lib/QuoteOrganization.php
a491ad28ed31a55d075a263cf5437ca9fb8efc2a58d11a67d54513036329c614  lib/QuotePdfService.php
6ba90614b43e73410f30bc69257d6a6962c61ed8e81a4356e48110e7367cdaf5  lib/QuotePdfSource.php
889c0c7e95695c6f1c8e90ef238c946f5944310829199e4de5125360e9528f5f  lib/QuotePdfToken.php
852a12ceb55a3dcb77c5f9a94b232ad17c922fb0ad903e3947b1223b1d04c540  lib/QuoteSendEvidence.php
1de99240e0e279f78aa356ef8d8e3c459a6e544cbbd071f0a0547035e6e1cd78  lib/QuoteTaxLine.php
662e92542687d5a9bbe2579283e0c46ee895b6126a744011d5f727f5a085b5d5  lib/QuoteTracker.php
ac78de79ee42857ff13bc23506b36b3fb3f605477cbdb50ceaf7aefc4cbc1f0c  lib/QuoteWaLedger.php
f5d78b56c6c38507cf924b6a311f5748a7e1e55f996a7187bd13821610c21bb8  lib/RbacService.php
75054999f6e6aba7602d0ee6fe2f23c1c964dd9018528726ab2f35f9b594fbc5  lib/ReceiptOnce.php
7349c9b16451e8e58e284c5d6f5cbc90bc042f9be6c32424db526bc17be80dc5  lib/RechargeService.php
e1d8b87280c3283e92ed283f96655a9923f819b4cca5d86b4a432d9453da1125  lib/ReminderDay.php
6ba465446b84974b7cfd6c51eccdae9cdbef6a3317aca8239e8bb34178c45382  lib/ReplyPrivacyGuard.php
c014a313a138ae214b09c863e5dba5f45663ba24cdf1f06fb172bf4a7960ce46  lib/ReplyTotals.php
fd3f3ccc469138ddbebc6ccf11a316cb1aedd3928ddc1547b99694a76184cd67  lib/ReportingService.php
163adb0281fd87b80d3aaa59c8cc52cf317755310c2e12c2d72e71b25006f193  lib/RetailerAuth.php
cb8fb939223b7504e8a0b2c375b413e4409ba6a5e7e26e1805a31ae2c291b8d2  lib/SalesFollowupChecks.php
70a0f38e6ce1436f32535cd52a69d1c77eb4c82c743a2b31297c1a508761011a  lib/SalesFollowupLadder.php
50d9f7a77504df40999594dd5b7e1820394a3cba2fae17dc2c6156bd4ffb57a6  lib/SalesNumbersAdmin.php
3e6154516a8680420569e5995f3d14bbfbbd1baaccd3b47b0ed8d3d4d763ff4e  lib/SecureFile.php
326bd272f986cce7c888265d6e730e015cc7a4358cb29029fd4fcb972822aa7c  lib/SentCopy.php
90794b1592ed81f99a6cb390f767788f381116685f69e86b59831c7f5cfbd25b  lib/ServicePlan.php
3a828c9237a7c1d3085d115be530140a11b018ce6e1013b439c00b162178602c  lib/Services.php
cead58a9f0747acf4b8572320a007769c6acae402c56e7b7c9c935575d73c828  lib/ShadowCompare.php
95d13a5ebecd85e5749d71211cb88693c15e3e0aa15471f59d97149a1a38a135  lib/ShadowObservation.php
d1a6c581fc876b9b9380ddc7d57983331f5822a9bcfe9ecffbc4b075cf6c590e  lib/ShopBotPayload.php
db2d2672b07ae4619555ea5de2a4ceeba97461d4f0e0d41a194d5885f26d6cdc  lib/ShopCatalogue.php
d1da8bdf2dadfde686a1c220c6ddadc367564a084a028e2916d135d546b5e594  lib/ShopImages.php
914a632a2a212dd979009a8c56eef409f331e78a07f92c0699b03dfa0da950e0  lib/ShopPage.php
bc65192d692d94592c51338bf4fbd38357cae6cfd09711b05300acd96cc8a45f  lib/SiblingPlugin.php
63d15fac344738489ff2f53f31f34e5739150c48b4cb77507e87d78b17f0e935  lib/SimService.php
6f8acf5ffec4d5117611035daa9f8355838e5148c73b82a0d08685b47d6a727a  lib/SnapshotService.php
57a73d68002ec69aa012eb02c8a6e0bca6cf266b899c113679a6a7e7c902975e  lib/SplynxApiClient.php
c3571f3b4235cd9b70821ff36dd03ccc233149ff3f762947e98166bb3dfc0fb0  lib/SplynxCustomerService.php
b8e6f558657a59843e96edb1eb5baedb38deb922f1322b08eab3e35108b0963d  lib/SplynxTicketService.php
16e5ddf7cbb7c9cfd9a26f0a62c01c542382c1951efdcf557c23cf9f075ebd33  lib/SqliteStore.php
eae6580796eed26a39c3e99f2bfc89549edbc528cab0e7c72ddd77822ba91239  lib/SspAdvanceService.php
78f0da05cd92a3f3de937b6d72fc798724938196dbaf690548421bf45296d8fd  lib/SspImprestReportService.php
28cfc16ee9aec61023eb007574e192b472e3c74f2368fa6e5d21a0f9df454e83  lib/StaffCashPositionService.php
55cd9b8d34aa9854e3910df566c087fbe456617857ba4b552e49d42489c3e724  lib/StaffDirectory.php
ac2fdc5608769292f515a1b43cd3b17c049c3f764309c27f077ccd954f6b0f1b  lib/StaffJobsGate.php
617257f9bdb7d7f64fc01362eea69935406e0a80652025ea4317d02beb5cdd10  lib/StaffLedgerService.php
4ab8985f7bbfe98c2200c59abae4a85f589201927d778f31470bcf5198e239a7  lib/StaffLedgerWriter.php
b77ff5d811ba16a5bcdbefb358e57349883a732f3dc0c20b63190a21ac97f7b1  lib/StaffLink.php
e44e2d99ea3d877860d94221f450b50ff3eeec5fe46215867438956dde953d1f  lib/StaffTransferService.php
7638b53ecbd90a9d1589e0e8b42adbf969570a934dc530a990ecca0fcf02a476  lib/StalwartProvider.php
5b0b753e1df35b428236ab469cab97685dde736a015a39553440729a541cb9b4  lib/StarlinkAccounts.php
707d6488c27da5aed53e18bc6a650257696c2e1b857611774383d0c6d3863307  lib/StarlinkBlockBridge.php
772d2a43fb2f310fdbe4d1d48619b5999b2110af23f8b12e6eed2d726acf0d95  lib/StarlinkBlockService.php
c9cd8d39c0a9cdcee5f05c9130d98a81aab82b8065219fdd260ab16c57926a25  lib/StarlinkConnector.php
73bbb59447cd7e10fb7fd66bad2748c0a559780d179ec7aa16f532cb25fc049a  lib/StarlinkFleet.php
87cc8936687729569c049cf7a02ab016125519aa1d578d43393b0eee17bc9e87  lib/StarlinkInvoiceImport.php
628be59677fe5d91ffe6de50ecc328cfd1c6524f20ba76e9bcedff19ed8a92f4  lib/StarlinkLineDiscovery.php
26210573abdb3f0af11fa17b76b1a933006c078ce33d83629da1ff6298ef1525  lib/StarlinkMailClassifier.php
b63cebd8fcc153eafb3963347e08e1732131dedd1e4cd63cc2911b9763a209b4  lib/StarlinkOrderImport.php
01e1fc73f4164d01f1a9a717d8b96d726796d68288fe2d4b2761bf55da11ec7d  lib/StarlinkPortalConnector.php
c9e545c476f3584b17aa179e750359ad314055ff515c4c6a264b85878b4e71e1  lib/StarlinkRetryScope.php
4ba017e3508ef13487c3fcbb6c152a1e697885fbfc10e416f73d86812c42edd1  lib/StarlinkServiceState.php
29552f37adadf0977183da28dd880c172d96ce94b8aab6af50e679f2e45964e1  lib/StarlinkSessionStore.php
10f5d3d1ee78579d64e0acd288db8b717a381d6b3ea3e9f916abbdec5d1994c6  lib/StarlinkUsage.php
0ad4d42b16c3323982d065487b02a0a92fc5b0d8b79ba37e3734efe60956c197  lib/StockService.php
2bf179f2d798479c7dfb09a0de130cce342ee2c4bcabe8f00468156215d98186  lib/StoreInterface.php
d872265d19c040fca4946beb70d4b09c1d46bcfbe6795bf67f4463d3ecf9fc9f  lib/TemplateReplyEngine.php
6e37597c1e62fc7520c21feebc522ce0d07522dc41a81387fcff54203d185053  lib/TenantProfile.php
ed344bbeaa43ab5aa28b66de785957e88ad7fa34cf1fcb18addd6cae87df6f02  lib/TransactionIntegrityGuard.php
33b54382d3de103589ea816385d01fb12f32e4ecbebe77fbbea2050d448449e0  lib/Transcriber.php
011a16a20a04c1a896a3d6109433397a717cb81f964567750fcd952bd383fde0  lib/UcrmCatalogue.php
b6afe83097718b505ee1573a0b697607a564bc51c40e43ea9128e856ee81d937  lib/UcrmClientTarget.php
ffda9ef723029c3641a95f95885212f7c21930c28146c912f5244fecfa485111  lib/UcrmCustomerDataGateway.php
887b71f2545b6e63598b6fcae3faa4ebb1b50c25db77f0cece0b57e143ab2d7d  lib/UcrmLeadSync.php
b36af2de417ed7a574ea3260f8d1101f4cca93ab738b4c9eb133977e0712376a  lib/UcrmQuoteLines.php
da0aa750bb2c60ab950ab6c7ed8c8c23e8a80f139a698ebc932b3d0064403e1c  lib/UcrmQuoteLinks.php
98f77a2d7cc9d17784960f45e6052958d179515fd999873a6ea4b27a7dd2065e  lib/UcrmUser.php
29a3136871003d370a1886106e3d337682bd22299096cf4366fd3d4b7186408a  lib/UcrmUsers.php
a94ba19fc4bc849d503db2f5d595c5e6582d1e1ddb668d2112626a1b6d5bbc99  lib/UtcClock.php
642ecf76458edeb6e4f39a152b76297037f0b89ad74ecd2dd9157cd41a050350  lib/VoiceAck.php
1045280aa61232a3b8fd237281ff2e7fe7f1f0dcccc00ac49f58bf58adc73461  lib/VoiceAckLimits.php
a2aa75f869b262e6be5fa9d2b11f4e07ad3cefbb44b3645dca97e18fa63d3d65  lib/VoiceBudget.php
9d9415427c43434c048f8b5c0b8e7188b4c30c19c6bddd3b10035136365fd612  lib/VoiceDuration.php
852d0d38f3e4f42c12b486a76794c0321cc47ed284f1a29c2bc4ded337fd2582  lib/VoiceSteps.php
ee6a3d82b9442a2a5e21f3b2a09d853bf62315f6247bf08ee4943bd5a5c112e9  lib/VoiceTranscription.php
264d798989aad541cdfb2374e7ce71ad6ab9c2392ba09e93f3c5db1c3070a740  lib/WaAutoReplyService.php
e760cd1ff8a638f7561f5b909011938dd4eab68e4b85febb609c9c710a5aab0a  lib/WaBotService.php
f6fdba806cc5caf86c01217fd2563cca8a253b13193d164c538b846039e50949  lib/WaInbound.php
c5698f7c2b4054f7d1990e0b5f176804f74448d9cea0e23e189a576de7c8c114  lib/WaLocation.php
ec60c81f7b3fb9d0a895a4e8f5858e2439e48809bb21087bb372299c2b00742f  lib/WaMessageProcessor.php
59bd92f29d60e4879cab13842f46dd474db644da95d7d3aeb69fff1eb3aab827  lib/WaSeenMarks.php
8f7c1dcdaa25e97cd0f49ba3a852e1cce8e86e6bac84513803b6274020849dea  lib/WaSendBudget.php
d0944681469cfa6352e9a3c76a4534f4c715c3b0fd5b6fdb97a94004eb3bfb96  lib/WalletService.php
e5f477369758f2ef605ee251bf3a8513f0005c44ed4e2c32eaa42da9975e76cc  lib/WeafEfrisClient.php
9975e3eb42f5dfe7f69c2cc5fa6515704b219687c4c403c90794a7065440a283  lib/WebChatGuard.php
275e2cc4769e7e9ecab798e6839ce884f142e0f751255452bbfdd85d979e98d2  lib/WebhookRegistrar.php
dadab93cfa183ef386b05ee5291bd50de2126f1f4d7fe0edd7434283e9fccc54  lib/WhatsAppAccess.php
5c8ec98df07425ad394ee57760578365ef77f359e1523e41c802685830a341c8  lib/WhatsAppChannel.php
7163f67c954be01789bc6f3bc01fc04f28a32207f00ce0ea5a65ba7f85cec8df  lib/WinBack.php
4747168bff0e0eb44846c9b72814a985f9ab02d2b5f68936a24b2a9e4134d553  lib/XlsxReader.php
1bb2565515fca8a9783650bec2fe5558a217ee966ab50a6f6110995f673b9e6c  lib/bootstrap_data.php
b60fb6807456634b947bd1332ffb1e305e0c7273b33ee9f1ab7ab7ec62fc514d  lib/crm_url.php
ed9e0e3bfbf7c24ffdaca183c7f31fea84c4780a872878ca156ea6d1768510fc  lib/currency.php
91aacb676559fa42879c379a919e4e401ab56d3ada5a53b4152bd7b74815f155  lib/error_handler.php
f995201e7af0b1ca5d8763a26942b0e108566ce8f209b3d5a76f1786c7062dcf  lib/fiber_data_import.php
05d48740e95280fbf95e7b96b0ddfc34f884d071d21b0be47d6720c0e0e8b0ed  lib/timezone.php
61991fc08af9afd6a543f3da4d73e1c272a45887f1f09bc1eadd53589f708c9e  lib/wa_webhook_url.php
3896d0c38949c7e5e7d0daeada2da60e33afda9ba87b4b364bea4da960c26b83  profiles/south-sudan.json
3ea8ccf703004e053d376314aedc2a03357ae3d2115923cc4dc892b835bf30b8  profiles/uganda.json
SUMS
)"
CONTROL_SHA256="cb57b70f5b9b2a44895a96f4351c0ce50a08660c676bd2cda8118488593aecab"   # sha256 of the string dnb-values-107
TS="$(date -u +%Y%m%dT%H%M%SZ)"
LOGDIR="${DNB_LOG_DIR:-/root/dnb-sales}"
LOG="$LOGDIR/values-5.18.107-$TS.log"

mask() { sed -E 's/[0-9]{9,}/<digits>/g'; }
mkdir -p "$LOGDIR" && chmod 700 "$LOGDIR" && : > "$LOG" && chmod 600 "$LOG" || { echo "STOP: the log $LOG could not be made; nothing was read"; exit 2; }
exec > >(tee -a "$LOG") 2>&1
stop() { printf '\n  STOP: %s\n  READ-ONLY — nothing was changed.\n  Send back the log:  cat %s\n' "$*" "$LOG"; exit 2; }

printf '== 5.18.107 production values — read-only — %s — %s ==\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$(hostname)"
command -v docker >/dev/null 2>&1 || stop "docker is not available here"
[ "$(sha256sum "$READER" 2>/dev/null | cut -c1-64)" = "$READER_SHA256" ] || stop "scripts/values-5.18.107.php is not the reviewed one (sha256) — run: cd $(cd "$HERE/.." && pwd) && git pull origin claude/study-this-jhe2eg, then this again"
docker exec "$CONTAINER" test -f "$IN_CONTAINER/manifest.json" </dev/null 2>/dev/null || stop "the plugin is not installed at $IN_CONTAINER in the '$CONTAINER' container"
PDD="$(docker exec "$CONTAINER" php -r '$u=@json_decode((string)@file_get_contents($argv[1]),true); echo rtrim((string)($u["pluginDataDir"]??""),"/");' "$IN_CONTAINER/ucrm.json" 2>/dev/null </dev/null || true)"
if [ -z "$PDD" ]; then
  if docker exec "$CONTAINER" test -f "$PLUGINS_IN/.$PLUGIN-data/plugin.sqlite3" </dev/null 2>/dev/null; then PDD="$PLUGINS_IN/.$PLUGIN-data"; else PDD=""; fi
fi
[ -n "$PDD" ] && [ "${PDD%/}" != "$IN_CONTAINER/data" ] || stop "the plugin's data folder could not be found beside the plugin"
DB="$PDD/plugin.sqlite3"
docker exec "$CONTAINER" test -f "$DB" </dev/null 2>/dev/null || stop "no book (plugin.sqlite3) in the data folder"
OWNER="$(docker exec "$CONTAINER" stat -c '%u:%g' "$DB" 2>/dev/null </dev/null || true)"
case "$OWNER" in ''|*[!0-9:]*) stop "the book's owner could not be read" ;; 0:*) stop "the book is root's — the plugin's own user must own it" ;; esac
REC="$(docker exec "$CONTAINER" cat "$IN_CONTAINER/.deployed-commit" 2>/dev/null </dev/null | tail -n1 | tr -cd '0-9a-f')"
[ "${#REC}" -ge 7 ] && [ "${LIVE_COMMIT_FULL#"$REC"}" != "$LIVE_COMMIT_FULL" ] || stop "the installed plugin records commit '${REC:-none}', not 5.18.106 (${LIVE_COMMIT_FULL:0:7}): these readings were written for 5.18.106"
N="$(grep -c . <<< "$LIVE_SUMS")"
GOT="$(printf '%s\n' "$LIVE_SUMS" | docker exec -i -u "$OWNER" "$CONTAINER" php -d display_errors=0 -r '
  echo "CONTROL ", hash("sha256", "dnb-values-107"), "\n"; $n = 0;
  while (($l = fgets(STDIN)) !== false) { $f = trim(substr($l, 66)); if ($f === "") continue; $n++;
    $p = $argv[1] . "/" . $f;
    if (!is_file($p)) {   // missing only when the nearest folder that exists can be listed; else it could not be read
      $d = dirname($p); while (!is_dir($d) && $d !== dirname($d)) $d = dirname($d);
      echo (is_readable($d) && is_executable($d)) ? "MISSING" : "UNREAD", "  ", $f, "\n"; continue; }
    $h = is_readable($p) ? @hash_file("sha256", $p) : false;
    echo ($h === false ? "UNREAD" : $h), "  ", $f, "\n"; }
  echo "END ", $n, "\n";' "$IN_CONTAINER" 2>/dev/null)"
[ "$(sed -n 's/^CONTROL \([0-9a-f]\{64\}\)$/\1/p' <<< "$GOT")" = "$CONTROL_SHA256" ] && grep -qx "END $N" <<< "$GOT" \
  || stop "the installed files could not be checked: php in the '$CONTAINER' container did not hash a known string, or did not answer for all $N files (nothing was compared)"
RES="$(awk 'NR == FNR { want[$2] = $1; order[++n] = $2; next }
            $1 != "CONTROL" && $1 != "END" { got[$2] = $1 }
            END { for (i = 1; i <= n; i++) { f = order[i]; g = got[f];
                    if (g == "MISSING") print "MISSING " f; else if (g !~ /^[0-9a-f]+$/ || length(g) != 64) print "UNREAD " f; else if (g != want[f]) print "BAD " f } }' \
        <(printf '%s\n' "$LIVE_SUMS") <(printf '%s\n' "$GOT"))"
MISSING="$(sed -n 's/^MISSING //p' <<< "$RES" | tr '\n' ' ')"; UNREAD="$(sed -n 's/^UNREAD //p' <<< "$RES" | tr '\n' ' ')"; BAD="$(sed -n 's/^BAD //p' <<< "$RES" | tr '\n' ' ')"
[ -z "$MISSING" ] || stop "these 5.18.106 files are not installed: $MISSING"
[ -z "$UNREAD" ] || stop "these installed files could not be read by the book's owner, so they were not compared: $UNREAD"
[ -z "$BAD" ] || stop "these installed files are not 5.18.106's bytes: $BAD"
echo "  installed       5.18.106 (commit $REC); all $N files of lib/, profiles/ and the shop catalogue are 5.18.106's bytes; the book owned by $OWNER"
echo "  reader          scripts/values-5.18.107.php (sha256 ${READER_SHA256:0:12}…), fed on stdin; nothing is copied into the container"

ROUT="$(mktemp)" || stop "a file of this run's could not be made"
WAIT_S="${DNB_VALUES_WAIT:-70}"; case "$WAIT_S" in ''|*[!0-9]*) WAIT_S=70 ;; esac   # seconds the reader waits for a plugin process
trap 'rm -f "$ROUT"' EXIT
docker exec -i -u "$OWNER" "$CONTAINER" php -d display_errors=stderr -d log_errors=0 -d opcache.enable_cli=0 -- "$IN_CONTAINER" "$PDD" "$WAIT_S" < "$READER" > "$ROUT" 2>&1
RC=$?
mask < "$ROUT"
# the reader's own last line ("READ-ONLY — …") says whether it ran to its end; an exit code of 1 alone could be docker's
FINISHED=no; grep -q '^READ-ONLY — ' "$ROUT" && FINISHED=yes
rm -f "$ROUT"

echo
echo "── W.8: the container's PATH (for the rehearsal's fake docker) ───────────────────────────────────────────"
echo "  as the container's default user: $(docker exec "$CONTAINER" printenv PATH 2>/dev/null </dev/null | mask)"
echo "  as the book's owner:             $(docker exec -u "$OWNER" "$CONTAINER" printenv PATH 2>/dev/null </dev/null | mask)"
echo
echo "  exit $RC (0 done, nothing changed; 1 the reading stopped part-way; 2 refused before reading; 3 done, but SQLite's own empty -wal/-shm were created beside the book by this read (it was idle, or closed as it was opened); 4 the book reported changes — UNEXPECTED)"
case "$FINISHED:$RC" in
  yes:0) echo "  READ-ONLY — nothing was changed" ;;
  yes:1|yes:3|yes:4) echo "  the reader's last line above says what happened; send the log as it is" ;;
  *) echo "  the reader ended without its last line (exit $RC): it may not have run at all (the lines above say why); nothing it runs writes; send the log as it is" ;;
esac
echo "  Send back the log:  cat $LOG"
exit "$RC"
