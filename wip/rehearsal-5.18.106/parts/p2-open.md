1. Nothing was executed.
   - rh106q.py was only parsed with ast over stdin.
   - rh106-seed.php was not linted: php -l is barred while the suite runs. It had a crude bracket-and-quote balance check over stdin (balanced) and a line-by-line read for strict_types issues.
   - Part 1's guard should run `php -l` on it, and the first dev trial is its real test.
   - The verbs contract (`lk=1 qftool=0 wrapper=0 verbs=0` on deploy-5.18.106.sh) was confirmed by equivalent greps, not by running the mode.
2. **verbs is unchanged from rh105q.py, deliberately.** I did not extend it to guard set_config.php. The script itself runs set_config.php on a throwaway folder (Q-CA6, S:5482), and S:12336 assigns the printed owner command to OWNER_AUTOSEND_CMD outside echo. Either would make an extended count non-zero and break the contract.
3. **u1mirror does not mirror the registry's held channels.** AutomationPolicy::sqlHeld depends on ChannelRegistry contexts and refusals. HELD is the caller's statement instead (`-`, `out:…` or `only:…`), and the uj's own `held=` is the comparison.
   - UG defaults to 1. TenantProfile over the vault is not mirrored; the sandbox is Uganda.
   - Colleague numbers use the Uganda profile's dial code 256 with length 9, because the copy's settings keep neither portal_dial_code nor contact_country_code.
   - Everything else in the classing order — attemptTaken, the opt-out rows, OwnedNumberHold through wa_channels' owner_type, human_active, replied, F2, and F1's refusedWhileDelivering — is mirrored from bf29cbd's code.
4. **The mirror reads the live book and the live settings files; the script reads a copy.** The copy's settings are cut to a keep list. Every key the mirror reads is in that list (QF_PHP conf, S:6320-6325), but a key outside it would make the two readings differ.
   - Pass NOW as the at= of UJ_A, so the 24 h and 30-day windows match.
   - Read immediately after the run: nothing may write the book in between.
5. **rwd's start time is the first approved draft by decided_at, id.** If two drafts share a decided_at, the sender's tie order follows SQLite's plan, not id; this matters only for J3-like pairs.
   - rblast sorts the files with Python, where bash uses the locale's collation. Only ties on RB_COPY_AT are affected.
6. **staleorder's p1 forecast assumes followup_enabled on and Uganda.** It also assumes LIMIT_HOURS is the limit the close job will read. Its main loop follows the close job's order: due_at ascending, where a NULL due_at sorts first, as in SQLite.
7. **Seed constraints Parts 1, 3 and 5 must plan for:**
   - **The range.** RH106_RANGE must be exported to the seed and be the range Part 1 proves free. Any 10-digit prefix in the fictitious 2567001xxx block is accepted. Only 100 numbers exist per range.
   - **Fresh numbers for re-plants.** A conversation's last_customer_at never moves back, and enquiry refuses when the customer last wrote later than the chosen lca. So every re-plant (D2, D3, D5) needs fresh numbers, and so do the J1 delivery conversations.
   - **Follow-ups can never be deleted.** The fu_never_deleted trigger forbids it, so a planted follow-up is undone only by closing it.
   - **Who runs which op.** Book ops refuse any uid but the book's owner. fx_state_* must run as root, since the state file is root's. fx_file must run as root, or as the file's owner when that is not root.
8. **fx_store_keys_undo restores the row through load() and save().** It asserts every other key is identical (===) after the round trip. The row's bytes match the pre-fixture cfgsha s= only if the row was last written by save(). A stored empty object `{}` comes back as `[]`, so design-v2 §A9's reading check (LK-G1 and CS-G1 clean) stands as the undo's proof. The file fixtures are restored byte for byte, by sha256.
9. **fx_type3 needs set_config's state.** kyc_config.json and the store row must both hold the key as the same digit string (e.g. after `set_config --key followup_daily_cap --value 3`); otherwise it refuses.
10. **The gone op asserts only half of §A3's order.** It checks the planted lca is older than every open enquiry follow-up's at plant time. Present stale rows planted after it must be younger; staleorder (gone_older, gone_first, first400_gone) is the check.