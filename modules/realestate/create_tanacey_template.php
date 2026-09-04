<?php
/**
 * Script to create the 2026 Tanacey Contract Template
 * Run this once to add the template to your database
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$currentCompanyId = current_company_id($conn) ?: 1;
$userId = current_user_id();

// Template content with placeholders
$templateContent = <<<'TEMPLATE'
Plot No. : _______________
وثيقة ايجار
TENANCY CONTRACT
Date : {TODAY_DATE}
Landlord name: {COMPANY_NAME_SETTING}
Tenant name: {TENANT_NAME}
Subject of Tenancy: {UNIT_NUMBER} - {BUILDING_NAME}
Period of Tenancy: {START_DATE} to {END_DATE}
Premises No (DEWA): {PREMISES_NUMBER}
Rent: {ANNUAL_RENT}
Terms of Payment: {NUMBER_OF_INSTALLMENTS} installments via {PAYMENT_METHOD}
Security Deposit: {SECURITY_DEPOSIT}
المؤجر: {COMPANY_NAME_SETTING}
المستأجر: {TENANT_NAME}
موضوع الايجار: {UNIT_NUMBER} - {BUILDING_NAME}
مدة الإيجار: {START_DATE} إلى {END_DATE}
رقم عداد الكهرباء: {PREMISES_NUMBER}
قيمة الإيجار: {ANNUAL_RENT}
اقساط الدفع: {NUMBER_OF_INSTALLMENTS} أقساط عبر {PAYMENT_METHOD}
وديعة التامين: {SECURITY_DEPOSIT}

Conditions Mutually Agreed Upon as Under:
الشروط المتفق عليها كما يلي:

1.The Tenant, upon signing this Lease Agreement,
acknowledges having received the Leased Premises and its
يقرّ المستأجر، بعد توقيعه على هذا العقد، بأنه قد تسلّم
appurtenances in good condition, suitable for the intended
العين المؤجرة وملحقاتها بحالة جيدة وصالحة للاستعمال،
use, and free from any defects that may hinder such use, in
وخالية من أي عيوب تؤثر على الانتفاع بها، وذلك وفقاً لما
accordance with the terms set forth herein. The Tenant
نص عليه هذا العقد. كما يتعهّد المستأجر بإعادة العين
further undertakes to return the Leased Premises to the
المؤجرة إلى المؤجر أو من ينيبه عند انتهاء مدة العقد أو
Landlord or their authorized representative upon the
فسخه لأي سبب، بالحالة ذاتها التي كانت عليها وقت
expiration or termination of the lease term, in the same
التسليم، مع مراعاة الاستهلاك العادي الناتج عن الاستخدام
condition as received, subject to normal wear and tear
المألوف، وذلك عملاً بأحكام القانون رقم (26) لسنة 2007
resulting from ordinary use. This clause is in accordance with
بشأن تنظيم العلاقة بين مؤجري ومستأجري العقارات في
Law No. (26) of 2007 Regulating the Relationship between
إمارة دبي وتعديلاته.
Landlords and Tenants in the Emirate of Dubai, and its
amendments.

2. Upon vacating the Leased Premises, the Tenant
يتعهد المستأجر، عند إخلاء العين المؤجرة، بإعادتها إلى
undertakes to return the property in good condition, similar
المالك وهي في حالة جيدة ومماثلة للحالة التي كانت عليها
to its condition at the commencement of the lease, subject
عند بدء عقد الإيجار، مع مراعاة الاستهلاك العادي الناتج
to normal wear and tear. The Tenant shall be responsible, at
عن الاستخدام المعتاد. كما يلتزم المستأجر بإصلاح أو تحمل
their own expense, for repairing or restoring any damage,
تكلفة إصلاح أي أضرار، أو كسور، أو فقدان، أو تغييرات
breakages, loss, unauthorized additions, or alterations made
أو إضافات غير مصرح بها طرأت على العين المؤجرة خلال
to the property during the lease term. If the Tenant fails to
فترة الإيجار، وذلك على نفقته الخاصة. وفي حال عدم قيام
carry out the necessary repairs, the Landlord shall have the
المستأجر بهذه الإصلاحات، يحق للمالك تقييم الأضرار من
right to assess the condition of the property through their
خلال فريق الصيانة الخاص به بعد استلام العقار، وتحميل
maintenance team after handover and charge the Tenant the
المستأجر كامل تكلفة الإصلاح وفقًا للتقييم.
full cost of repair based on such assessment.

3. The Tenant shall not affix carpets, flooring, or any
يلتزم المستأجر بعدم تثبيت أو لصق السجاد أو أي
installations within the Leased Premises using adhesives or
أرضيات أو تركيبات داخل العين المؤجرة باستخدام المواد
any other materials that may cause damage to the flooring
اللاصقة أو غيرها من الوسائل التي قد تُحدث ضرراً
or the structural integrity of the property. The Tenant is
بالأرضيات أو البنية التحتية للعقار، كما لا يجوز له إجراء
further prohibited from carrying out any alterations, internal
أي تعديلات أو أعمال داخلية أو تركيب لافتات أو حروف
works, or installing signs, written letters, trademarks, or any
مكتوبة أو علامات تجارية أو أي عروض مرئية أو متحركة
visual or moving displays inside or on the Leased Premises
داخل أو على العين المؤجرة، إلا بعد الحصول على موافقة
without obtaining the Landlord's prior written consent.Upon
خطية مسبقة من المؤجر. وعند انتهاء مدة الإيجار، يلتزم
the expiration or termination of the lease term, the Tenant
المستأجر بإزالة جميع الإضافات أو التعديلات التي قام بها،
shall be obligated to remove all such additions or
وإعادة العين المؤجرة إلى حالتها الأصلية التي كانت عليها
modifications made during the lease period and restore the
عند بدء العلاقة الإيجارية، مع إصلاح أي ضرر ناتج عن تلك
Leased Premises to its original condition as it was at the
التعديلات على نفقته الخاصة، وذلك دون الحاجة إلى توجيه
commencement of the lease. The Tenant shall bear the full
إنذار رسمي من المؤجر. ويحق للمؤجر، في حال عدم التزام
cost of such removal and any necessary repairs resulting
المستأجر بذلك، خصم التكاليف اللازمة للإصلاح أو الإزالة
from said alterations, without the need for formal notice
من مبلغ التأمين المودع لديه، دون الإخلال بحقه في المطالبة
from the Landlord.
بأية مبالغ إضافية إن لزم الأمر، وفقاً لأحكام القوانين المعمول
The Landlord shall have the right to deduct the cost of such
بها في إمارة دبي.
removal and/or repairs from the security deposit held,
without prejudice to the Landlord's right to claim any
additional amounts should the cost of restoration exceed
the deposit, in accordance with the applicable laws and
regulations in the Emirate of Dubai.

4. The Tenant shall be fully responsible for the maintenance
يلتزم المستأجر بمسؤولية كاملة عن صيانة وإصلاح
and repair of all works and modifications carried out by the
جميع الأعمال والتعديلات التي قام بها هو أو المستأجر
Tenant or any previous tenant within the Leased Premises,
السابق داخل العين المؤجرة، والتي تشمل على سبيل المثال
including but not limited to internal decorations, plumbing
لا الحصر: الديكورات الداخلية، أعمال السباكة، التمديدات
works, electrical installations, air conditioning systems, and
الكهربائية، التكييف، وتركيب أسلاك الحاسب الآلي والاتصالات،
installation of computer or communication wiring, as well as
وأي إضافات أو تغييرات غير جزء من التجهيزات الأصلية
any additions or alterations not part of the original property
للعقار. ويتحمل المستأجر كافة التكاليف الناجمة عن هذه
fittings. The Tenant shall bear all costs related to such
الصيانة والإصلاح طوال مدة عقد الإيجار، كما يلتزم
maintenance and repairs throughout the Lease Term, and
بالحفاظ على العين المؤجرة في حالة جيدة وعدم التسبب
shall keep the Leased Premises in good condition, refraining
بأي أضرار ناتجة عن سوء الاستخدام أو الإهمال. ويلتزم
from causing any damage due to misuse or negligence.The
المالك بمسؤولية صيانة وتجديد التجهيزات الأصلية والثابتة
Landlord shall be responsible for the maintenance and
في العين المؤجرة، بما يشمل كافة المكونات والتجهيزات
renewal of the original and fixed fixtures within the Leased
التي كانت موجودة قبل بداية فترة الإيجار.
Premises, including all components and fittings existing prior
to the commencement of the Lease Term

5. Upon signing the lease agreement, both parties shall
عند توقيع عقد الإيجار، يقوم الطرفان بإعداد وتوقيع
prepare and sign a detailed inventory report that reflects the
جردة حالة تفصيلية توضح الوضع الحالي للعين المؤجرة،
current condition of the leased premises, including all
بما في ذلك كافة التجهيزات الأصلية والتركيبات الإضافية.
original fittings and any additional installations. This
تعتبر هذه الجردة وثيقة رسمية تُستخدم كمرجع أساسي
inventory shall serve as an official document and a primary
لتقييم حالة العين المؤجرة عند انتهاء مدة الإيجار، بهدف
reference for assessing the condition of the leased premises
تحديد الأضرار أو التغيرات التي يكون المستأجر مسؤولاً عن
upon the termination of the lease, in order to determine any
إصلاحها أو تحمل تكاليفها. ويلتزم كل من المالك والمستأجر
damages or changes for which the Tenant is responsible for
بالاحتفاظ بنسخة من هذه الجردة، كما يلتزمان بتوثيق أي
repairing or covering the costs. Both the Landlord and the
ملاحظات أو تعديلات تطرأ على حالة العين المؤجرة طوال
Tenant shall retain a copy of this inventory and commit to
فترة الإيجار.
documenting any remarks or modifications to the condition
of the leased premises throughout the lease term.

6. The Tenant shall permit the Landlord or his authorized
يلتزم المستأجر بالسماح للمالك أو من ينوب عنه من
agents or workers to enter the leased premises at
وكلاء أو عمال بالدخول إلى العين المؤجرة في الأوقات
reasonable and appropriate times for the purpose of
المناسبة والمعقولة، وذلك لغرض معاينة وفحص حالة العين
inspecting and assessing the condition of the premises,
المؤجرة، والإشراف على أعمال الإصلاح والصيانة أو
supervising, or carrying out repair and maintenance works.
تنفيذها. على أن يتم إبلاغ المستأجر مسبقًا بموعد الزيارة ما
The Tenant shall be notified in advance of the intended visit,
لم تكن الحالة طارئة تستدعي الدخول الفوري.
except in cases of emergency where immediate access is
required.

7. The Tenant shall obtain prior written approval from the
يلتزم المستأجر بالحصول على موافقة خطية مسبقة من
Landlord before moving in any new furniture into the leased
المالك قبل نقل أو إدخال أي أثاث جديد إلى العين المؤجرة أو
premises or disposing of the existing furniture upon vacating
التصرف في الأثاث القديم عند إخلاء العين المؤجرة. يجب على
the premises. The Tenant must present a clearance letter
المستأجر تقديم خطاب التحرير (إخلاء الأثاث) كما من المالك
from the Landlord before commencing any furniture moving
قبل بدء عمليات نقل أو تعبئة الأثاث.
or packing operations

8. The Tenant shall not install any television antenna,
لا يجوز للمستأجر تركيب هوائي تلفزيون أو طبق استقبال
satellite dish, or similar equipment within or outside the
(ستالايت) أو أي تجهيزات مشابهة داخل أو خارج العين
Leased Premises, including on walls, roofs, or balconies,
المؤجرة، بما في ذلك على الجدران أو الأسطح أو الشرفات،
without obtaining the prior written consent of the
دون الحصول على موافقة خطية مسبقة من المالك. وفي حال
Landlord.In the event that the Tenant proceeds with such
قيام المستأجر بأي تركيب دون الحصول على هذه الموافقة،
installations without the required approval, the Landlord or
يحق للمالك أو من ينوب عنه إزالة تلك التركيبات دون إشعار
its authorized representative shall have the right to remove
مسبق للمستأجر، وتحميله كافة التكاليف والنفقات المترتبة
the unauthorized installations without prior notice to the
على الإزالة أو الإصلاح أو أي أضرار ناجمة عنها، دون
Tenant. All related costs, including removal, repair, and any
الإخلال بحق المالك في اتخاذ أي إجراءات قانونية إضافية
resulting damages, shall be borne solely by the Tenant,
وفق القوانين المعمول بها في إمارة دبي.
without prejudice to the Landlord's right to pursue further
legal action in accordance with the applicable laws and
regulations of the Emirate of Dubai.

9. In the event that there are any applicable municipal
في حال وجود أي قيود أو لوائح بلدية معمول بها تتعلق
restrictions or regulations concerning the use of LPG
باستخدام أسطوانات غاز البترول في العقارات السكنية،
cylinders in residential properties, the Tenant shall fully
يلتزم المستأجر بالامتثال الكامل لهذه القوانين واللوائح.
comply with such laws and regulations. The Tenant shall be
ويكون المستأجر مسؤولاً بشكل كامل عن أي خسائر أو أضرار
solely responsible for any losses or damages arising from
تنشأ نتيجة عدم الامتثال، وكذلك عن جميع المطالبات
non-compliance, as well as for any claims or liabilities
القانونية أو التعويضات التي قد تترتب على ذلك.
resulting therefrom.

10. The Tenant must not remove any fixtures (e.g., wiring,
يتعهد المستأجر بعدم إزالة أي من التركيبات المثبتة (مثل
pipes, or sanitary fittings) if it would damage the property. If
الأسلاك الكهربائية، أنابيب المياه، التركيبات الصحية) في
this obligation is breached, the Landlord may claim the full
الجدران أو الهيكل المدمج في العين المؤجرة إذا كان ذلك
cost of repairs and compensation, in line with Dubai laws
سيؤدي إلى ضرر في البنية التحتية أو التشطيبات. وفي حال
and may escalate the matter to the Dubai Rental Disputes
الإخلال، يحق للمؤجر المطالبة بالتعويض الكامل عن الأضرار
Center or other relevant authorities.
والخسائر المباشرة والتبعية، وفقًا للقوانين السارية في إمارة
دبي، واللجوء في حال الإخلال بحقوقه دون المساس إلى الجهات
المختصة.

11. The Tenant shall not carry out any substantial repairs or
يلتزم المستأجر بعدم تنفيذ أي إصلاحات أو تعديلات جوهرية
modifications to the leased premises without the prior
في العين المؤجرة دون الحصول على موافقة خطية مسبقة
written consent of the Landlord. Any unauthorized work
صريحًا من المؤجر. وتُعد أي مخالفة خرقًا للعقد وتخول
shall be considered a breach of this agreement and shall
المؤجر المطالبة بالتعويض.
entitle the Landlord to claim damages

12. The Tenant hereby acknowledges their full obligation to
يقرّ المستأجر بالتزامه التام بسداد الإيجار في المواعيد
settle the rent payments on the dates specified and agreed
المحددة والمتفق عليها في هذا العقد، سواء عن طريق
upon in this Agreement, whether by post-dated cheques or
شيكات مؤجلة أو وسائل دفع أخرى معتمدة. وفي حال ارتداد
any other approved payment methods.
أي من الشيكات المقدمة من المستأجر لأي سبب يتعلق به،
In the event that any cheque issued by the Tenant is returned
تُفرض عليه غرامة تعاقدية مقدارها (500) خمسمائة درهم
unpaid for any reason attributable to the Tenant, a
عن كل شيك مرتجع. وفي حال عدم قيام المستأجر بسداد
contractual penalty of AED 500 (Five Hundred Dirhams) shall
المبلغ المستحق الناتج عن الشيك المرتجع خلال مدة خمسة
be imposed for each bounced cheque.
أيام (5) من تاريخ إشعاره خطيًا من قبل المؤجر، فيحق
If the Tenant fails to settle the outstanding amount resulting
للأخير التقدّم بطلب فسخ العقد واستعادة العين المؤجرة،
from the bounced cheque within five (5) days from the date
وفقًا للمادة (25) من القانون رقم (26) لسنة 2007 وتعديلاته.
of written notice issued by the Landlord, the Landlord shall
كما تُفرض غرامة تأخير مقدارها (500) خمسمائة درهم عن
have the right to request termination of this Agreement and
كل حالة تأخير في سداد الإيجار الشهري أو السنوي، على أن
reclaim the Leased Premises, in accordance with Article (25)
لا تتعارض هذه الغرامة مع ما نص عليه القانون، وتُعد جزءًا
of Law No. (26) of 2007, as amended.
من الالتزامات المالية للمستأجر.
Furthermore, a late payment penalty of AED 500 (Five
Hundred Dirhams) shall be imposed for each instance of
delay in paying the monthly or annual rent. This penalty shall
be deemed a part of the Tenant's financial obligations and
shall not conflict with the provisions of applicable law.

13. The lease agreement shall not be considered effective
يُعتبر عقد الإيجار نافذًا ولا يُسلَّم للمستأجر إلا بعد تسوية
nor delivered to the Tenant until the first rent installment
أول شيك من القسط الإيجاري من قبل البنك، واستيفاء
cheque is cleared by the bank and the Tenant has submitted
المستأجر لجميع المستندات والمستندات المطلوبة من قبل
all documents required by the Landlord or his authorized
المالك أو وكيله. وفي حال عدم استكمال هذه الشروط، يحتفظ
representative. Failure to fulfill these conditions entitles the
المالك بحق عدم تسليم العقد أو تعليق سريان مفعوله حتى
Landlord to withhold delivery of the lease agreement or
تمام التنفيذ.
suspend its effectiveness until full compliance is met.

14. The Tenant shall not have the right to terminate this
لا يحق للمستأجر إنهاء هذا العقد قبل انتهاء مدته
Lease Agreement prior to the expiry of its fixed term, unless
المحددة، ما لم تتم الموافقة الخطية المسبقة من قبل المالك
prior written approval is granted by the Landlord. In the
على ذلك. وفي حال وافق المالك على إنهاء العقد قبل الأجل،
event that the Landlord consents to an early termination,
يلتزم المستأجر بدفع مبلغ يعادل قيمة إيجار ثلاثة (3) أشهر
the Tenant shall be liable to pay an early termination
كتعويض إنهاء مبكر، على أن يُدفع هذا المبلغ قبل أو عند
compensation equivalent to three (3) month's rent, which
تاريخ الإخلاء الفعلي. ويُعد هذا البند جزءًا لا يتجزأ من
shall be payable prior to or upon the actual date of vacating
الالتزامات التعاقدية للطرفين.
the Leased Premises. This clause shall constitute an integral
part of the contractual obligations of both parties.

15. This Lease Agreement shall not be automatically
لا يُجدد هذا العقد تلقائيًا عند انتهاء مدته، ويجوز تجديده
renewed upon the expiration of its term. Renewal for an
لمدة مماثلة فقط بموجب اتفاق خطي صريح بين الطرفين،
equivalent period shall only occur pursuant to an express
ووفقًا للشروط التي يتم الاتفاق عليها. ويشترط أن يُقدّم أي
written agreement between the Parties and subject to
من الطرفين إشعارًا خطيًا للطرف الآخر يُعبّر فيه عن رغبته
mutually agreed terms.
في عدم تجديد العقد، وذلك قبل تسعين (90) يومًا على الأقل
Either Party wishing not to renew the Lease must serve a
من تاريخ انتهاء مدة الإيجار. وفي حال قدّم المستأجر هذا
written notice to the other Party at least ninety (90) days
الإشعار خلال مدة تقل عن تسعين (90) يومًا، فإنه يلتزم
prior to the expiration date of the Lease Term.In the event
بدفع ما يعادل تسعين (90) يومًا من الإيجار كغرامة تأخير،
that the Tenant submits such notice less than ninety (90)
تُحسب من تاريخ انتهاء العقد أو تاريخ تسليم العين
days before the expiration date, the Tenant shall be liable to
المؤجرة، أيهما أسبق. ولا يُعدّ عدم تقديم الإشعار تجديدًا
pay a late notice penalty equivalent to ninety (90) days of
تلقائيًا للعقد، كما لا يُلزم المؤجر بتجديد العقد ما لم يُبرم
rent, calculated from the earlier of the Lease expiration date
اتفاق خطي صريح بين الطرفين.
or the actual handover date of the Leased Premises.
Failure to provide such notice shall not be construed as a
renewal of the Lease, nor shall it obligate the Landlord to
renew the Lease in the absence of an express written
agreement between the Parties.

16. In the event that the Tenant intends to vacate the Leased
في حال رغب المستأجر في إخلاء العين المؤجرة أو
Premises or permanently leave the United Arab Emirates
مغادرة دولة الإمارات العربية المتحدة قبل انتهاء مدة العقد،
prior to the expiration of the Lease Term, the Tenant shall
يتعين عليه إشعار المؤجر أو وكيله خطيًا بذلك، والحصول
notify the Landlord or the Landlord's agent in writing and
على موافقة خطية مسبقة. وفي حال قام المستأجر بإخلاء
obtain prior written approval.
العين المؤجرة بشكل مفاجئ ودون الحصول على هذه
If the Tenant vacates the Leased Premises without such
الموافقة، أو ثبت أن العين المؤجرة قد تُركت شاغرة دون
approval, or it is established that the Premises have been left
إشغال فعلي ولم يتم الوفاء بالالتزامات التعاقدية، يحق
unoccupied and the Tenant has failed to fulfill contractual
للمؤجر أو وكيله التقدم بطلب رسمي إلى الجهات المختصة
obligations, the Landlord or the Landlord's agent shall have
(مثل مركز فض المنازعات الإيجارية بدبي) لاتخاذ الإجراءات
the right to file an official request with the relevant
اللازمة لاستعادة حيازة العين المؤجرة، وفقًا لما تقرره
authorities (such as the Dubai Rental Dispute Center) to take
القوانين والأنظمة السارية. ولا يحق للمستأجر في هذه الحالة
the necessary legal action to repossess the Leased Premises
الاعتراض على إجراءات المؤجر القانونية لاسترداد العين
in accordance with the applicable laws and regulations.
المؤجرة، دون أن يخل ذلك بحق المؤجر في المطالبة
In such case, the Tenant shall have no right to object to the
بالتعويض عن الأضرار أو المستحقات المتبقية بموجب العقد.
legal procedures undertaken by the Landlord to recover the
Premises, without prejudice to the Landlord's right to claim
any damages or outstanding dues under the Lease
Agreement.

17. The Tenant shall not sublease the leased premises,
يلتزم المستأجر بعدم تأجير العين المؤجرة من الباطن
whether in whole or in part, nor assign or permit any third
كليًا أو جزئيًا، أو التنازل عنها، أو السماح لأي طرف ثالث
party to occupy or use the premises in any manner, whether
باستخدامها بأي شكل من الأشكال، سواء بمقابل أو بدون
for consideration or otherwise, without the prior express
مقابل، دون الحصول على موافقة خطية مسبقة وصريحة
written consent of the Landlord. The Tenant is strictly
من المالك. كما يُحظر على المستأجر استخدام العين المؤجرة
prohibited from using the leased premises for any purpose
في غير الغرض السكني المنصوص عليه في هذا العقد. وفي
other than the residential purpose stated in this Agreement.
حال خرق هذا الالتزام، يحق للمالك اتخاذ كافة الإجراءات
In the event of a breach of this provision, the Landlord shall
القانونية، بما في ذلك الإخلاء الفوري والمطالبة بالتعويض.
have the right to take all necessary legal actions, including
كما يتعهد المستأجر بتزويد المالك أو وكيله بنسخ من بطاقات
immediate eviction and claiming damages.
الهوية أو الإقامات السارية لكافة الأفراد المقيمين معه في
Furthermore, the Tenant undertakes to provide the Landlord
العين المؤجرة، خلال مدة لا تتجاوز (7) أيام من تاريخ سكنهم،
or his authorized representative with copies of valid
وذلك لأغراض أمنية وتنظيمية.
identification or residency documents for all individuals
residing with the Tenant in the leased premises, within
seven (7) days from the date of their occupancy, for security
and regulatory purposes.

18. in the event that either party to this Agreement wishes
في حال رغبة أي من طرفي هذا العقد في تعديل أي شرط
to amend any of its terms or conditions, the party seeking
من شروطه أو أي بند من بنوده، يلتزم الطرف الراغب
such amendment shall notify the other party in writing at
بالتعديل بإخطار الطرف الآخر بذلك إخطارًا خطيًا قبل مدة لا
least thirty (30) days prior to the expiration date of this
تقل عن ثلاثين (30) يومًا من تاريخ انتهاء مدة هذا العقد.
Agreement. Failure to provide such notice within the
وفي حال عدم تقديم الإخطار خلال المدة المحددة، يُعد العقد
specified period shall result in the automatic renewal of this
مجددًا تلقائيًا بذات الشروط والأحكام السابقة، ما لم يتفق
Agreement under the same terms and conditions, unless
الطرفان كتابةً على خلاف ذلك.
otherwise agreed in writing between the parties

19. The Tenant shall bear full responsibility for the payment
يتحمل المستأجر كامل المسؤولية عن سداد جميع
of all governmental, municipal, and housing fees, as well as
الرسوم الحكومية والبلدية ورسوم الإسكان، وكذلك رسوم
the registration fees of the Lease Agreement with the Real
تسجيل عقد الإيجار لدى مؤسسة التنظيم العقاري (ريرا)،
Estate Regulatory Agency (RERA), whether such fees are
سواء كانت هذه الرسوم مستحقة في الوقت الحالي أو قد
currently due or may become due in the future, in relation
تُستحق في المستقبل، وذلك عن العين المؤجرة طوال مدة
to the Leased Premises throughout the lease term. The
الإيجار. كما يلتزم المستأجر بسداد جميع فواتير المياه
Tenant shall also be responsible for the timely settlement of
والكهرباء والصرف الصحي والخدمات المرتبطة بالعين
all utility bills including water, electricity, sewage, and any
المؤجرة، ويُعتبر أي تأخير أو تقصير في السداد إخلالاً
other service charges related to the Leased Premises. Any
بالتزاماته التعاقدية.
delay or failure in payment shall be deemed a breach of the
Tenant's contractual obligations.

20. The Tenant undertakes to pay a minimum service fee of
يلتزم المستأجر بدفع الحد الأدنى من رسوم الخدمة
AED 1,500 or 2% of the annual rent, whichever is higher, for
المقررة بمبلغ 1,500 درهم إماراتي أو بنسبة 2% من قيمة
each renewal of the lease contract
الإيجار السنوي، أيهما أعلى، وذلك عن كل عملية تجديد لعقد
الإيجار.

21. In the event the Tenant has paid a security deposit, such
عند إخلاء العين المؤجرة، يجوز للمستأجر استرداد مبلغ
deposit may be refunded upon vacating the leased premises,
التأمين، وذلك بعد خصم تكاليف إصلاح الأضرار التي لحقت
subject to deductions for costs related to repairing any
بالعقار، وأي فواتير غير مدفوعة لاستخدام المرافق، بما في
damage to the property and any unpaid utility bills,
ذلك الفواتير النهائية للكهرباء والمياه الصادرة عن هيئة
including the final electricity and water bills issued by the
الكهرباء والمياه بدبي. ويتم استرداد مبلغ التأمين خلال فترة
Dubai Electricity and Water Authority (DEWA). The refund
لا تتجاوز خمسة وعشرين (25) يوم عمل من تاريخ تقديم
shall be processed within twenty-five (25) working days from
الفاتورة النهائية.
the date the final invoice is submitted.

22. The Tenant is permitted to keep pets in the apartment,
يُسمح للمستأجر باقتناء حيوانات أليفة داخل الشقة
subject to a non-refundable pet fee of AED 500 (Five
المؤجرة، وذلك مقابل رسوم غير قابلة للاسترداد قدرها
Hundred Dirhams) per pet. The Tenant shall ensure that the
(500) خمسمائة درهم عن كل حيوان أليف. ويلتزم المستأجر
presence of any pet does not cause damage to the property
بعدم التسبب بأي إزعاج للسكان الآخرين أو إلحاق ضرر
or disturbance to other residents. The Tenant shall be held
بالممتلكات نتيجة وجود الحيوانات الأليفة. ويتحمل المستأجر
liable for any damages or additional cleaning costs arising
المسؤولية الكاملة عن أي أضرار أو تكاليف تنظيف إضافية
from the keeping of pets in the Leased Premises.
ناتجة عن اقتناء الحيوانات الأليفة داخل العين المؤجرة.

23. Tenants shall not engage in any activities that may cause
لا يجوز للمستأجرين ممارسة أي أنشطة من شأنها التسبب
disturbance, nuisance, or inconvenience to neighbors or
في الإزعاج أو الإضرار براحة الجيران أو سكان المبنى،
other occupants of the building. All Tenants must observe
ويجب عليهم الالتزام بالسلوك العام واحترام القواعد
proper conduct and adhere to the rules and regulations
المعمول بها في العقار. يُمنع منعًا باتًا تخزين أو ترك أية
applicable to the Property.
ممتلكات شخصية أو مواد في الممرات، الشرفات أو مداخل
The storage or placement of any personal belongings or
الوحدات، أو أي من المناطق المشتركة في المبنى. ويحق
materials in hallways, balconies, apartment entrances, or
للمؤجر أو إدارة المبنى إزالة أي ممتلكات تُترك بالمخالفة
any of the building's common areas is strictly prohibited.
لهذا البند دون إشعار مسبق، ولا يتحمل المؤجر أية مسؤولية
The Landlord or Building Management reserves the right to
عن ضياع أو تلف تلك الممتلكات، كما يحتفظ بحقه في تحميل
remove any such items left in violation of this clause without
المستأجر أي تكاليف ناتجة عن الإزالة أو التنظيف أو الأضرار
prior notice and shall not be held liable for any loss or
ذات الصلة.
damage resulting therefrom. The Landlord further reserves
the right to charge the Tenant for any costs incurred in
relation to the removal, cleaning, or related damage.

24.The Landlord, the property agent, and their respective
لا يتحمل المالك أو الوكيل العقاري أو أي من ممثليهما
representatives or affiliates shall not be held liable for any
القانونيين أو التابعين لهما، أي مسؤولية عن أية إصابات
bodily injuries or material damages sustained by the Tenant
جسدية أو أضرار مادية قد يتعرض لها المستأجر أو زواره
or their guests while using the swimming pool or any other
نتيجة استخدام حمام السباحة أو أي من المرافق المشتركة
shared or communal facilities within the building. The
أو العامة داخل المبنى. ويقر المستأجر بأنه يخدم هذه
Tenant acknowledges that use of such facilities is at their
المرافق على مسؤوليته الخاصة، مع التزامه بالتقيد بكافة
own risk and agrees to comply with all rules and safety
التعليمات والإرشادات الصادرة من إدارة المبنى أو الجهة
instructions issued by the building management or facility
المشغّلة للمرافق.
operator.

25. If the Property or Building is equipped with parking
في حال كان العقار أو المبنى مزودًا بخدمات مواقف
facilities, the Tenant shall be entitled to use one parking
سيارات، سيتم تخصيص فتحة موقف سيارة واحدة للمستأجر
space for their personal vehicle during the Lease Term.
خلال مدة الإيجار، وذلك دون أن يترتب على المالك أية
The Landlord shall not, under any circumstances, be held
مسؤولية تجاه سلامة أو حماية أو فقدان أو تلف أو سرقة
liable for any damage, loss, theft, or destruction of the
المركبة أثناء وجودها في الموقف، بغض النظر عن سبب
vehicle while parked in the allocated space, regardless of the
الواقعة. حيث تقع مسؤولية تأمين المركبة بالكامل على عاتق
cause.
المستأجر. كما يُطبق رسم إضافي في حال طلب المستأجر
The Tenant shall bear full responsibility for insuring the
تخصيص موقف سيارة إضافي، ويخضع ذلك لموافقة المالك
vehicle.
وتوفر المساحة.
An additional parking space may be provided, subject to
availability and the Landlord's prior approval, and shall be
subject to additional charges.

26.If the Building provides any facilities such as a gym,
في حال وجود أية مرافق داخل المبنى مثل الجيم،
swimming pool, sauna, steam room, jacuzzi, or other shared
حمام السباحة، الساونا، غرفة البخار، الجاكوزي، أو
amenities, the Tenant shall undertake to use these facilities
غيرها من المرافق المشتركة، يلتزم المستأجر باستخدام
in accordance with applicable laws and regulations,
هذه المرافق بما يتوافق مع القوانين واللوائح المعمول
respecting their safety and condition, and refraining from
بها، وأن يحترم سلامة المرافق وألا يسيء إليها بأي شكل من
any misuse or damage.
الأشكال. ويقتصر استخدام هذه المرافق على المستأجر
Use of these facilities is strictly limited to the Tenant and
وأفراد أسرته فقط، ولا يجوز السماح للغير باستخدامها دون
their immediate family members only, and third-party use is
موافقة المالك أو إدارة المبنى.
prohibited without the prior consent of the Landlord or
Building Management.

27.In the event the Tenant breaches any of the terms
في حال خرق المستأجر لأي من الشروط الواردة في هذا
stipulated in this Agreement, the Landlord shall notify the
العقد، يلتزم المالك بإخطار المستأجر كتابيًا بوجوب معالجة
Tenant in writing to remedy the breach within a period of no
هذا الخرق خلال مدة لا تقل عن (15) يومًا من تاريخ الإخطار.
less than fifteen (15) days from the date of such notice. If the
في حال عدم تصحيح المستأجر للخرق خلال هذه المدة، يحق
Tenant fails to rectify the breach within this period, the
للمالك فسخ العقد وطلب إخلاء العين المؤجرة، مع تحميل
Landlord shall have the right to terminate the Agreement
المستأجر كافة الأضرار والخسائر التي تنجم عن هذا
and demand evacuation of the leased premises, holding the
الانتهاك. كما يحق للمالك اتخاذ كافة الإجراءات القانونية
Tenant liable for all damages and losses arising from such
اللازمة لاسترداد حقوقه، بما في ذلك المطالبة بالتعويضات
breach. The Landlord shall also be entitled to take all
والتكاليف القانونية، وفقًا لأحكام هذا العقد والقوانين السارية
necessary legal actions to enforce his rights, including
في إمارة دبي.
claiming compensation and legal costs, in accordance with
the provisions of this Agreement and the applicable laws of
the Emirate of Dubai.

28. In the event that there are authorized signatories or
في حال وجود مفوضين بالتوقيع أو ممثلين لشركات، يجب
company representatives involved, legally certified powers
تقديم الوكالات القانونية المعتمدة التي تفيد بحقهم في
of attorney must be provided evidencing their authority to
التوقيع أو التمثيل في هذا الشأن، ويتم إرفاقها بالعقد.
sign or represent in this matter, and such documents shall be
attached to the contract.

29.In the event of any dispute arising between the Landlord
في حال نشوب أي نزاع بين المالك والمستأجر يتعلق
and the Tenant in relation to the interpretation,
بتفسير أو تنفيذ أو تطبيق أحكام هذا العقد، يتم إحالة
implementation, or enforcement of the provisions of this
النزاع إلى المحاكم المختصة في إمارة دبي للفصل فيه،
Agreement, such dispute shall be referred to the competent
وتكون هذه المحاكم صاحبة الولاية القضائية الحصرية.
courts of the Emirate of Dubai, which shall have exclusive
jurisdiction.

30. In the event of any discrepancy or conflict in
في حال وجود أي تعارض أو اختلاف في التفسير بين النص
interpretation between the Arabic text and the translated
العربي والنص المترجم لهذا العقد، يُعتد بالنص العربي
version of this Agreement, the Arabic text shall prevail and
ويُعتبر هو المرجع الرسمي والملزم قانونًا، وتسود أحكامه
shall be deemed the official and legally binding version
أمام كافة الجهات القضائية والإدارية في إمارة دبي.
before all judicial and administrative authorities in the
وتُوقّع جميع الأطراف على النسخة العربية باعتبارها
Emirate of Dubai. All parties acknowledge and sign the
النسخة المعتمدة والأساسية للعقد.
Arabic version as the primary and authoritative version of
the Agreement.

31. This contract is made in two copies, and both the parties
تم إبرام هذا العقد من نسختين ويحصل بموجبه كل
are deemed to have received a cop contract upon signing.
طرف على نسخة من العقد بعد التوقيع عليه.

Remarks:
____________________________________________________________________________________
{{LEASE_NOTES}}
____________________________________________________________________________________________
Tenant's Phone: {{TENANT_PHONE}} Tenant's Emirates ID: {{TENANT_ID_NUMBER}}
Tenant's Email: {{TENANT_EMAIL}}

I undertake to act in accordance with this Contract and its Conditions.
أتعهد بالعمل وفق هذه الاتفاقية وشروطها

Landlord's Signature: ___________________________ Tenant's Signature: ___________________________
TEMPLATE;

try {
    $conn->beginTransaction();
    
    // Check if template already exists
    $stmt = $conn->prepare("SELECT id FROM re_contract_templates WHERE template_name = ? AND company_id = ?");
    $stmt->execute(['2026 Tanacey Contract', $currentCompanyId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        // Update existing template
        $stmt = $conn->prepare("
            UPDATE re_contract_templates 
            SET template_content = ?, is_active = 1, is_default = 1
            WHERE id = ? AND company_id = ?
        ");
        $stmt->execute([$templateContent, $existing['id'], $currentCompanyId]);
        $message = "Template updated successfully!";
    } else {
        // Unset other defaults
        $stmt = $conn->prepare("UPDATE re_contract_templates SET is_default = 0 WHERE company_id = ?");
        $stmt->execute([$currentCompanyId]);
        
        // Create new template
        $stmt = $conn->prepare("
            INSERT INTO re_contract_templates 
            (company_id, template_name, template_type, description, template_content,
             is_active, is_default, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $currentCompanyId,
            '2026 Tanacey Contract',
            'standard',
            'Bilingual (English/Arabic) lease contract template based on Law No. (26) of 2007 for Dubai real estate',
            $templateContent,
            1, // is_active
            1, // is_default
            $userId
        ]);
        $message = "Template created successfully!";
    }
    
    $conn->commit();
    
    echo "<!DOCTYPE html>
    <html>
    <head>
        <title>Template Created</title>
        <style>
            body { font-family: Arial, sans-serif; padding: 40px; background: #f5f5f5; }
            .success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
            .info { background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; padding: 20px; border-radius: 5px; }
            a { color: #007bff; text-decoration: none; }
            a:hover { text-decoration: underline; }
        </style>
    </head>
    <body>
        <div class='success'>
            <h2>✅ Success!</h2>
            <p>$message</p>
        </div>
        <div class='info'>
            <h3>Next Steps:</h3>
            <ol>
                <li>Go to <a href='lease_templates.php'>Contract Templates</a> to view your template</li>
                <li>When viewing a lease, click <strong>\"Generate Contract\"</strong> to create a contract with auto-filled information</li>
                <li>The template will automatically replace placeholders like {TENANT_NAME}, {UNIT_NUMBER}, {ANNUAL_RENT}, etc. with actual lease data</li>
            </ol>
        </div>
        <p><a href='lease_templates.php'>← Back to Templates</a> | <a href='leases.php'>View Leases</a></p>
    </body>
    </html>";
    
} catch (Exception $e) {
    $conn->rollBack();
    echo "<!DOCTYPE html>
    <html>
    <head>
        <title>Error</title>
        <style>
            body { font-family: Arial, sans-serif; padding: 40px; background: #f5f5f5; }
            .error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 20px; border-radius: 5px; }
        </style>
    </head>
    <body>
        <div class='error'>
            <h2>❌ Error</h2>
            <p>" . htmlspecialchars($e->getMessage()) . "</p>
        </div>
        <p><a href='lease_templates.php'>← Back to Templates</a></p>
    </body>
    </html>";
}

