# 62 — Starlink Installation Terms — DRAFT — SUBJECT TO LEGAL REVIEW (INSTALLATION-TERMS-v1.0, 2026-10-05)

**Status: DRAFT — SUBJECT TO LEGAL REVIEW. This is a business and technical implementation; nothing here is legally
approved, and nothing in it is intended to exclude any right Ugandan law does not allow to be excluded.** It is the text a
customer reads and accepts on the Customer Installation Authorisation page (`public.php?page=install_auth`) before a
Starlink installation may start (docs/61 §3, docs/63). The text below is **byte for byte** the constant
`InstallationTerms::TEXT_V1_0` in `dishnet-hybrid-sudan/lib/InstallationTerms.php`; its SHA-256 is pinned by
`tests/test_install_authorisation.php` and stored on every acceptance record beside the version name, so an acceptance
made under v1.0 stays reproducible after any later version.

| | |
|---|---|
| Version | `INSTALLATION-TERMS-v1.0` |
| SHA-256 of the exact text | `9308072840e784e49a0656987a3edfae33c6f3d081558cbf63625c23b071d2e1` |
| Length | 8226 bytes, 25 numbered sections and a preamble |
| Rendered by | `tabs/customer_app/install_auth_page.php` from the version the record names — never from "the latest" |
| Changing it | a NEW constant and a NEW version name (`INSTALLATION-TERMS-v1.1`), added to `InstallationTerms::VERSIONS`; v1.0 is never edited |

## How the brief's 25 topics map to the sections

| Brief topic | Section |
|---|---|
| 1 Customer authorisation | 1 |
| 2 Scope of installation | 2 |
| 3 Installation charges | 3 |
| 4 Transport charges | 4 |
| 5 Additional materials/work | 5 |
| 6 Site access | 6 |
| 7 Electricity/power/access requirements | 7 |
| 8 Customer responsibility for accurate information | 8 |
| 9 Scheduling | 9 |
| 10 Technician attendance | 10 |
| 11 Cancellation before dispatch | 11 |
| 12 Cancellation after dispatch | 12 |
| 13 Customer refusal after work has commenced | 13 — the brief's main protection, with its "subject to … rights that cannot lawfully be excluded" tail |
| 14 Installation completion | 14 |
| 15 Customer sign-off | 15 — kept distinct from the authorisation in section 1 (the brief's phase 16) |
| 16 Equipment handling | 16 |
| 17 Site/customer-caused damage | 17 |
| 18 Service activation | 18 |
| 19 Billing | 19 |
| 20 Refund/cancellation provisions | 20 — no blanket "no refund" clause anywhere |
| 21 Dispute resolution | 21 |
| 22 Electronic acceptance | 22 |
| 23 Data/privacy | 23 |
| 24 Terms version | 24 |
| 25 Limitation of liability where legally permissible | 25 — ends with the brief's cautious clause, verbatim |

## Questions for legal review (none of them answered here)

1. Whether sections 12 and 13 (the transport charge after dispatch; the charges after work has commenced) are enforceable as
   worded under the Consumer Protection and Competition Act and related Ugandan consumer law, and whether a cooling-off
   period applies to an installation authorised electronically.
2. Whether section 17 (damage) and section 25 (limitation) need to name the categories of liability that cannot be limited.
3. Whether section 21 should name a specific regulator or complaint process for communications services in Uganda.
4. Whether section 23 satisfies the Data Protection and Privacy Act, 2019 as a notice, or must point to a fuller policy.
5. Whether electronic acceptance as recorded (section 22: reference, time, version, hash, snapshots; no IP address or device
   data by decision D11) is sufficient evidence of consent, or whether the record should hold more.
6. Whether the Starlink equipment and service terms (sections 16 and 18) should be referenced by name and version.
7. Whether the heading should carry the registered company name exactly as it appears on the certificate of incorporation.

The implementation facts a reviewer needs beside these questions (what the system records and what it does not) are in
`docs/64` §I.2 — facts, not legal opinion. The text below is unchanged by 5.18.83.

## The text (INSTALLATION-TERMS-v1.0)

```text
DISHNET AFRICA LIMITED — STARLINK INSTALLATION TERMS
Version INSTALLATION-TERMS-v1.0
DRAFT — SUBJECT TO LEGAL REVIEW

These Installation Terms ("Terms") apply to the installation of Starlink equipment and related work ("Installation") that DishNet Africa Limited ("DishNet", "we", "us") carries out for the customer named in the Installation Job ("Customer", "you"). The Installation Job is the record of the specific installation these Terms are accepted for: it names the job number, the installation location, the service, the equipment, the charges and, where one has been set, the scheduled date.

1. Customer authorisation
By accepting these Terms you authorise DishNet to carry out the Installation described in the Installation Job, at the installation location, for the charges shown at the time of acceptance. Acceptance is recorded electronically (section 22). DishNet will not commence the Installation before this authorisation is recorded.

2. Scope of installation
The Installation covers the work described in the Installation Job: mounting the Starlink dish at a suitable position agreed on site, routing the cable, placing and connecting the router and power supply, and confirming that the Starlink service is reachable from the equipment. Work not described in the Installation Job is outside the scope of this authorisation.

3. Installation charges
The installation charge is the amount shown in the Installation Job at the time of acceptance. It covers the labour and the standard materials for the scope in section 2.

4. Transport charges
Where a transport charge is shown in the Installation Job, it covers the technician's travel to the installation location. If no transport charge is shown, none is payable for the scheduled visit.

5. Additional materials and work
Materials or work beyond the standard scope (for example extra cable length, poles, brackets, trenching, conduit or electrical work) are not included unless they are listed in the Installation Job. If additional materials or work become necessary on site, the technician will explain what is needed and what it costs before carrying it out, and will proceed only with your agreement. You may decline additional work; the Installation is then completed as far as the authorised scope allows, or rescheduled.

6. Site access
You will provide safe access to the installation location at the scheduled time, including access to roofs, walls, rooms and any other area where the equipment or the cable is to be placed. Where the property is rented or shared, you confirm that you have the permission needed for the Installation.

7. Electricity, power and access requirements
A working mains power point is needed where the router and the power supply will be placed, and the dish needs a position with a clear view of the sky. You are responsible for providing power at the site and for any electrical work that is not part of the Installation Job.

8. Accurate information
You confirm that the name, installation location, contact details and other information given for the Installation are accurate. DishNet relies on this information to plan the visit; inaccurate information may delay or prevent the Installation.

9. Scheduling
The scheduled date and time shown in the Installation Job is the planned visit. Either party may ask to reschedule by contacting the other before the visit, and DishNet will confirm any new date and time.

10. Technician attendance
DishNet will send a technician to the installation location at the scheduled time. The technician may ask you or your representative to confirm the dish position and the cable route before work begins. If nobody is available to give access at the scheduled time, the visit may need to be rescheduled and the transport charge shown in the Installation Job, where one is shown, may apply.

11. Cancellation before dispatch
You may cancel the Installation before the technician has been dispatched by contacting DishNet. No installation or transport charge is payable for a visit cancelled before dispatch.

12. Cancellation after dispatch
If the Installation is cancelled after the technician has been dispatched and before work has commenced, the transport charge shown in the Installation Job, where one is shown, may be payable.

13. Customer refusal after work has commenced
Once the Customer has accepted these Terms and DishNet has commenced the authorised installation works, the Customer remains responsible for applicable installation charges and other charges expressly agreed for the installation, subject to any cancellation, refund or other rights that cannot lawfully be excluded under applicable law.

14. Installation completion
The Installation is complete when the equipment has been installed as described in the Installation Job and the Starlink service has been confirmed reachable from the equipment, or when the parts of the work that can be completed on site have been completed and the remainder has been recorded with you.

15. Customer sign-off
At completion the technician may ask you or your representative to confirm that the Installation has been completed and received. Sign-off confirms receipt of the completed Installation; it is separate from, and does not replace, the authorisation given under section 1.

16. Equipment handling
The technician will handle the Starlink equipment with care and install it according to the manufacturer's guidance. The equipment remains subject to the terms under which it was purchased or supplied; these Terms do not change them.

17. Site and customer-caused damage
DishNet is responsible for damage caused by its technician's negligence during the Installation. To the extent permitted by applicable law, DishNet is not responsible for damage caused by conditions that existed at the site before the Installation, by instructions you give, or by work carried out by others.

18. Service activation
Starlink service activation and the Starlink service itself are provided under their own terms. The Installation makes the equipment ready for use; service availability, speed and coverage are not promised by these Terms.

19. Billing
Charges for the Installation are invoiced by DishNet in the currency shown in the Installation Job (Ugandan Shillings unless another currency is stated there). Payment terms are as stated on the invoice.

20. Refunds and cancellation
Refunds and cancellations are handled under sections 11 to 13 and applicable law. Nothing in these Terms removes any refund or cancellation right that cannot lawfully be excluded.

21. Dispute resolution
If you have a concern about the Installation, please contact DishNet first so that it can be resolved directly. A dispute that cannot be resolved by discussion is subject to the laws of Uganda and the jurisdiction of the courts of Uganda, without limiting any right to use a consumer complaint process available under applicable law.

22. Electronic acceptance
These Terms are accepted electronically through the secure DishNet acceptance page sent to you. DishNet records the acceptance reference, the time of acceptance, the version and hash of these Terms, and the installation details and charges shown to you at that time. That record is the evidence of your authorisation.

23. Data and privacy
DishNet uses the personal information connected with the Installation (your name, contact details and installation location) to plan and carry out the Installation, to communicate with you about it, and to keep the records described in section 22, in accordance with DishNet's Privacy Policy and applicable Ugandan data protection law.

24. Terms version
These Terms are version INSTALLATION-TERMS-v1.0. Your acceptance refers to this version only. A later version does not change an Installation accepted under this version.

25. Limitation of liability
To the extent permitted by applicable law, DishNet's liability in connection with the Installation is limited to the charges paid for the Installation, and DishNet is not liable for indirect or consequential loss. Nothing in these Terms is intended to exclude or restrict any right or remedy that cannot lawfully be excluded or restricted under applicable Ugandan law.
```

---

Draft only · Not legally approved · The constant in code is authoritative; this file is a copy for review
