<?php

namespace VentureDrake\LaravelCrm;

use App\Models\User;
use Dcblogdev\Xero\Models\XeroToken;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use VentureDrake\LaravelCrm\Console\Commands\IssueApiToken;
use VentureDrake\LaravelCrm\Console\LaravelCrmAddressTypes;
use VentureDrake\LaravelCrm\Console\LaravelCrmAddUser;
use VentureDrake\LaravelCrm\Console\LaravelCrmArchive;
use VentureDrake\LaravelCrm\Console\LaravelCrmContactTypes;
use VentureDrake\LaravelCrm\Console\LaravelCrmDecrypt;
use VentureDrake\LaravelCrm\Console\LaravelCrmEmailCampaignsDispatch;
use VentureDrake\LaravelCrm\Console\LaravelCrmEncrypt;
use VentureDrake\LaravelCrm\Console\LaravelCrmFields;
use VentureDrake\LaravelCrm\Console\LaravelCrmInstall;
use VentureDrake\LaravelCrm\Console\LaravelCrmLabels;
use VentureDrake\LaravelCrm\Console\LaravelCrmLeadSources;
use VentureDrake\LaravelCrm\Console\LaravelCrmMonitorCheck;
use VentureDrake\LaravelCrm\Console\LaravelCrmOrganizationTypes;
use VentureDrake\LaravelCrm\Console\LaravelCrmPermissions;
use VentureDrake\LaravelCrm\Console\LaravelCrmReminders;
use VentureDrake\LaravelCrm\Console\LaravelCrmSampleData;
use VentureDrake\LaravelCrm\Console\LaravelCrmSmsCampaignsDispatch;
use VentureDrake\LaravelCrm\Console\LaravelCrmUpdate;
use VentureDrake\LaravelCrm\Console\LaravelCrmUpgrade;
use VentureDrake\LaravelCrm\Console\LaravelCrmV2;
use VentureDrake\LaravelCrm\Console\LaravelCrmXero;
use VentureDrake\LaravelCrm\Http\Livewire\Components\LiveCall;
use VentureDrake\LaravelCrm\Http\Livewire\Components\LiveFile;
use VentureDrake\LaravelCrm\Http\Livewire\Components\LiveLunch;
use VentureDrake\LaravelCrm\Http\Livewire\Components\LiveMeeting;
use VentureDrake\LaravelCrm\Http\Livewire\Components\LiveNote;
use VentureDrake\LaravelCrm\Http\Livewire\Components\LiveTask;
use VentureDrake\LaravelCrm\Http\Livewire\Fields\CreateOrEdit;
use VentureDrake\LaravelCrm\Http\Livewire\Integrations\Xero\XeroConnect;
use VentureDrake\LaravelCrm\Http\Livewire\LiveActivities;
use VentureDrake\LaravelCrm\Http\Livewire\LiveActivityMenu;
use VentureDrake\LaravelCrm\Http\Livewire\LiveAddressEdit;
use VentureDrake\LaravelCrm\Http\Livewire\LiveCalls;
use VentureDrake\LaravelCrm\Http\Livewire\LiveDealBoard;
use VentureDrake\LaravelCrm\Http\Livewire\LiveDealForm;
use VentureDrake\LaravelCrm\Http\Livewire\LiveDeliveryDetails;
use VentureDrake\LaravelCrm\Http\Livewire\LiveDeliveryItems;
use VentureDrake\LaravelCrm\Http\Livewire\LiveEmailEdit;
use VentureDrake\LaravelCrm\Http\Livewire\LiveFiles;
use VentureDrake\LaravelCrm\Http\Livewire\LiveInvoiceForm;
use VentureDrake\LaravelCrm\Http\Livewire\LiveInvoiceLines;
use VentureDrake\LaravelCrm\Http\Livewire\LiveLeadBoard;
use VentureDrake\LaravelCrm\Http\Livewire\LiveLeadForm;
use VentureDrake\LaravelCrm\Http\Livewire\LiveLunches;
use VentureDrake\LaravelCrm\Http\Livewire\LiveMeetings;
use VentureDrake\LaravelCrm\Http\Livewire\LiveNotes;
use VentureDrake\LaravelCrm\Http\Livewire\LiveOrderForm;
use VentureDrake\LaravelCrm\Http\Livewire\LiveOrderItems;
use VentureDrake\LaravelCrm\Http\Livewire\LivePhoneEdit;
use VentureDrake\LaravelCrm\Http\Livewire\LiveProductForm;
use VentureDrake\LaravelCrm\Http\Livewire\LivePurchaseOrderForm;
use VentureDrake\LaravelCrm\Http\Livewire\LivePurchaseOrderLines;
use VentureDrake\LaravelCrm\Http\Livewire\LiveQuoteBoard;
use VentureDrake\LaravelCrm\Http\Livewire\LiveQuoteForm;
use VentureDrake\LaravelCrm\Http\Livewire\LiveQuoteItems;
use VentureDrake\LaravelCrm\Http\Livewire\LiveRelatedContactOrganization;
use VentureDrake\LaravelCrm\Http\Livewire\LiveRelatedContactPerson;
use VentureDrake\LaravelCrm\Http\Livewire\LiveRelatedPerson;
use VentureDrake\LaravelCrm\Http\Livewire\LiveTasks;
use VentureDrake\LaravelCrm\Http\Livewire\NotifyToast;
use VentureDrake\LaravelCrm\Http\Livewire\PayInvoice;
use VentureDrake\LaravelCrm\Http\Livewire\SendInvoice;
use VentureDrake\LaravelCrm\Http\Livewire\SendPurchaseOrder;
use VentureDrake\LaravelCrm\Http\Livewire\SendQuote;
use VentureDrake\LaravelCrm\Http\Middleware\Authenticate;
use VentureDrake\LaravelCrm\Http\Middleware\ForceJsonResponse;
use VentureDrake\LaravelCrm\Http\Middleware\FormComponentsConfig;
use VentureDrake\LaravelCrm\Http\Middleware\HasCrmAccess;
use VentureDrake\LaravelCrm\Http\Middleware\LastOnlineAt;
use VentureDrake\LaravelCrm\Http\Middleware\LogUsage;
use VentureDrake\LaravelCrm\Http\Middleware\RedirectToRouteDomain;
use VentureDrake\LaravelCrm\Http\Middleware\RouteSubdomain;
use VentureDrake\LaravelCrm\Http\Middleware\SetApiTeamContext;
use VentureDrake\LaravelCrm\Http\Middleware\Settings;
use VentureDrake\LaravelCrm\Http\Middleware\TeamsPermission;
use VentureDrake\LaravelCrm\Http\Middleware\XeroTenant;
use VentureDrake\LaravelCrm\Livewire\Activities\ActivityFeed;
use VentureDrake\LaravelCrm\Livewire\Activities\ActivityIndex;
use VentureDrake\LaravelCrm\Livewire\ActivityMenu;
use VentureDrake\LaravelCrm\Livewire\ActivityTabs;
use VentureDrake\LaravelCrm\Livewire\Auth\ForgotPassword;
use VentureDrake\LaravelCrm\Livewire\Auth\Login;
use VentureDrake\LaravelCrm\Livewire\Auth\ResetPassword;
use VentureDrake\LaravelCrm\Livewire\Calls\CallItem;
use VentureDrake\LaravelCrm\Livewire\Calls\CallRelated;
use VentureDrake\LaravelCrm\Livewire\Chat\ChatIndex;
use VentureDrake\LaravelCrm\Livewire\Chat\ChatShow;
use VentureDrake\LaravelCrm\Livewire\Dashboard;
use VentureDrake\LaravelCrm\Livewire\Deals\DealBoard;
use VentureDrake\LaravelCrm\Livewire\Deals\DealCreate;
use VentureDrake\LaravelCrm\Livewire\Deals\DealEdit;
use VentureDrake\LaravelCrm\Livewire\Deals\DealIndex;
use VentureDrake\LaravelCrm\Livewire\Deals\DealShow;
use VentureDrake\LaravelCrm\Livewire\Deliveries\DeliveryCreate;
use VentureDrake\LaravelCrm\Livewire\Deliveries\DeliveryEdit;
use VentureDrake\LaravelCrm\Livewire\Deliveries\DeliveryIndex;
use VentureDrake\LaravelCrm\Livewire\Deliveries\DeliveryRelatedIndex;
use VentureDrake\LaravelCrm\Livewire\Deliveries\DeliveryShow;
use VentureDrake\LaravelCrm\Livewire\EmailCampaigns\EmailCampaignCreate;
use VentureDrake\LaravelCrm\Livewire\EmailCampaigns\EmailCampaignEdit;
use VentureDrake\LaravelCrm\Livewire\EmailCampaigns\EmailCampaignIndex;
use VentureDrake\LaravelCrm\Livewire\EmailCampaigns\EmailCampaignShow;
use VentureDrake\LaravelCrm\Livewire\EmailTemplates\EmailTemplateCreate;
use VentureDrake\LaravelCrm\Livewire\EmailTemplates\EmailTemplateEdit;
use VentureDrake\LaravelCrm\Livewire\EmailTemplates\EmailTemplateIndex;
use VentureDrake\LaravelCrm\Livewire\EmailTemplates\EmailTemplateShow;
use VentureDrake\LaravelCrm\Livewire\Features\FeatureBoard;
use VentureDrake\LaravelCrm\Livewire\Features\FeatureCreate;
use VentureDrake\LaravelCrm\Livewire\Features\FeatureEdit;
use VentureDrake\LaravelCrm\Livewire\Features\FeatureIndex;
use VentureDrake\LaravelCrm\Livewire\Features\FeatureShow;
use VentureDrake\LaravelCrm\Livewire\Features\FeatureVoters;
use VentureDrake\LaravelCrm\Livewire\Files\FileItem;
use VentureDrake\LaravelCrm\Livewire\Files\FileRelated;
use VentureDrake\LaravelCrm\Livewire\GetLink;
use VentureDrake\LaravelCrm\Livewire\Invoices\InvoiceCreate;
use VentureDrake\LaravelCrm\Livewire\Invoices\InvoiceEdit;
use VentureDrake\LaravelCrm\Livewire\Invoices\InvoiceIndex;
use VentureDrake\LaravelCrm\Livewire\Invoices\InvoicePay;
use VentureDrake\LaravelCrm\Livewire\Invoices\InvoiceRelatedIndex;
use VentureDrake\LaravelCrm\Livewire\Invoices\InvoiceSend;
use VentureDrake\LaravelCrm\Livewire\Invoices\InvoiceShow;
use VentureDrake\LaravelCrm\Livewire\KanbanBoard;
use VentureDrake\LaravelCrm\Livewire\Leads\LeadBoard;
use VentureDrake\LaravelCrm\Livewire\Leads\LeadCreate;
use VentureDrake\LaravelCrm\Livewire\Leads\LeadEdit;
use VentureDrake\LaravelCrm\Livewire\Leads\LeadIndex;
use VentureDrake\LaravelCrm\Livewire\Leads\LeadShow;
use VentureDrake\LaravelCrm\Livewire\Lunches\LunchItem;
use VentureDrake\LaravelCrm\Livewire\Lunches\LunchRelated;
use VentureDrake\LaravelCrm\Livewire\Meetings\MeetingItem;
use VentureDrake\LaravelCrm\Livewire\Meetings\MeetingRelated;
use VentureDrake\LaravelCrm\Livewire\ModelAddresses;
use VentureDrake\LaravelCrm\Livewire\ModelEmails;
use VentureDrake\LaravelCrm\Livewire\ModelPhones;
use VentureDrake\LaravelCrm\Livewire\ModelProducts;
use VentureDrake\LaravelCrm\Livewire\Monitors\MonitorCreate;
use VentureDrake\LaravelCrm\Livewire\Monitors\MonitorEdit;
use VentureDrake\LaravelCrm\Livewire\Monitors\MonitorIndex;
use VentureDrake\LaravelCrm\Livewire\Monitors\MonitorShow;
use VentureDrake\LaravelCrm\Livewire\Notes\NoteItem;
use VentureDrake\LaravelCrm\Livewire\Notes\NoteRelated;
use VentureDrake\LaravelCrm\Livewire\Orders\OrderCreate;
use VentureDrake\LaravelCrm\Livewire\Orders\OrderEdit;
use VentureDrake\LaravelCrm\Livewire\Orders\OrderIndex;
use VentureDrake\LaravelCrm\Livewire\Orders\OrderRelatedIndex;
use VentureDrake\LaravelCrm\Livewire\Orders\OrderShow;
use VentureDrake\LaravelCrm\Livewire\Organizations\OrganizationCreate;
use VentureDrake\LaravelCrm\Livewire\Organizations\OrganizationEdit;
use VentureDrake\LaravelCrm\Livewire\Organizations\OrganizationImport;
use VentureDrake\LaravelCrm\Livewire\Organizations\OrganizationIndex;
use VentureDrake\LaravelCrm\Livewire\Organizations\OrganizationShow;
use VentureDrake\LaravelCrm\Livewire\People\PersonCreate;
use VentureDrake\LaravelCrm\Livewire\People\PersonEdit;
use VentureDrake\LaravelCrm\Livewire\People\PersonImport;
use VentureDrake\LaravelCrm\Livewire\People\PersonIndex;
use VentureDrake\LaravelCrm\Livewire\People\PersonShow;
use VentureDrake\LaravelCrm\Livewire\Portal\Features\PublicFeatureBoard;
use VentureDrake\LaravelCrm\Livewire\Portal\Features\PublicFeatureShow;
use VentureDrake\LaravelCrm\Livewire\Portal\Features\PublicFeatureSubmit;
use VentureDrake\LaravelCrm\Livewire\Products\ProductCreate;
use VentureDrake\LaravelCrm\Livewire\Products\ProductEdit;
use VentureDrake\LaravelCrm\Livewire\Products\ProductIndex;
use VentureDrake\LaravelCrm\Livewire\Products\ProductShow;
use VentureDrake\LaravelCrm\Livewire\Profile\DeleteUserForm;
use VentureDrake\LaravelCrm\Livewire\Profile\LogoutOtherBrowserSessionsForm;
use VentureDrake\LaravelCrm\Livewire\Profile\TwoFactorAuthenticationForm;
use VentureDrake\LaravelCrm\Livewire\Profile\UpdatePasswordForm;
use VentureDrake\LaravelCrm\Livewire\Profile\UpdateProfileInformationForm;
use VentureDrake\LaravelCrm\Livewire\PurchaseOrders\PurchaseOrderCreate;
use VentureDrake\LaravelCrm\Livewire\PurchaseOrders\PurchaseOrderEdit;
use VentureDrake\LaravelCrm\Livewire\PurchaseOrders\PurchaseOrderIndex;
use VentureDrake\LaravelCrm\Livewire\PurchaseOrders\PurchaseOrderRelatedIndex;
use VentureDrake\LaravelCrm\Livewire\PurchaseOrders\PurchaseOrderSend;
use VentureDrake\LaravelCrm\Livewire\PurchaseOrders\PurchaseOrderShow;
use VentureDrake\LaravelCrm\Livewire\Quotes\QuoteBoard;
use VentureDrake\LaravelCrm\Livewire\Quotes\QuoteCreate;
use VentureDrake\LaravelCrm\Livewire\Quotes\QuoteEdit;
use VentureDrake\LaravelCrm\Livewire\Quotes\QuoteIndex;
use VentureDrake\LaravelCrm\Livewire\Quotes\QuoteSend;
use VentureDrake\LaravelCrm\Livewire\Quotes\QuoteShow;
use VentureDrake\LaravelCrm\Livewire\RelatedDeals;
use VentureDrake\LaravelCrm\Livewire\RelatedOrganizations;
use VentureDrake\LaravelCrm\Livewire\RelatedPeople;
use VentureDrake\LaravelCrm\Livewire\Settings\ChatWidgets\ChatWidgetEdit;
use VentureDrake\LaravelCrm\Livewire\Settings\ChatWidgets\ChatWidgetIndex;
use VentureDrake\LaravelCrm\Livewire\Settings\ChatWidgets\ChatWidgetShow;
use VentureDrake\LaravelCrm\Livewire\Settings\CustomFieldGroups\CustomFieldGroupCreate;
use VentureDrake\LaravelCrm\Livewire\Settings\CustomFieldGroups\CustomFieldGroupEdit;
use VentureDrake\LaravelCrm\Livewire\Settings\CustomFieldGroups\CustomFieldGroupIndex;
use VentureDrake\LaravelCrm\Livewire\Settings\CustomFieldGroups\CustomFieldGroupShow;
use VentureDrake\LaravelCrm\Livewire\Settings\CustomFields\CustomFieldCreate;
use VentureDrake\LaravelCrm\Livewire\Settings\CustomFields\CustomFieldEdit;
use VentureDrake\LaravelCrm\Livewire\Settings\CustomFields\CustomFieldIndex;
use VentureDrake\LaravelCrm\Livewire\Settings\CustomFields\CustomFieldShow;
use VentureDrake\LaravelCrm\Livewire\Settings\Features\FeatureStatusCreate;
use VentureDrake\LaravelCrm\Livewire\Settings\Features\FeatureStatusEdit;
use VentureDrake\LaravelCrm\Livewire\Settings\Features\FeatureStatusIndex;
use VentureDrake\LaravelCrm\Livewire\Settings\Integrations\ClickSend\ClickSendConnect;
use VentureDrake\LaravelCrm\Livewire\Settings\Labels\LabelCreate;
use VentureDrake\LaravelCrm\Livewire\Settings\Labels\LabelEdit;
use VentureDrake\LaravelCrm\Livewire\Settings\Labels\LabelIndex;
use VentureDrake\LaravelCrm\Livewire\Settings\Labels\LabelShow;
use VentureDrake\LaravelCrm\Livewire\Settings\LeadSources\LeadSourceCreate;
use VentureDrake\LaravelCrm\Livewire\Settings\LeadSources\LeadSourceEdit;
use VentureDrake\LaravelCrm\Livewire\Settings\LeadSources\LeadSourceIndex;
use VentureDrake\LaravelCrm\Livewire\Settings\LeadSources\LeadSourceShow;
use VentureDrake\LaravelCrm\Livewire\Settings\Permissions\RoleCreate;
use VentureDrake\LaravelCrm\Livewire\Settings\Permissions\RoleEdit;
use VentureDrake\LaravelCrm\Livewire\Settings\Permissions\RoleIndex;
use VentureDrake\LaravelCrm\Livewire\Settings\Permissions\RoleShow;
use VentureDrake\LaravelCrm\Livewire\Settings\Pipelines\PipelineEdit;
use VentureDrake\LaravelCrm\Livewire\Settings\Pipelines\PipelineIndex;
use VentureDrake\LaravelCrm\Livewire\Settings\Pipelines\PipelineShow;
use VentureDrake\LaravelCrm\Livewire\Settings\PipelineStages\PipelineStageEdit;
use VentureDrake\LaravelCrm\Livewire\Settings\PipelineStages\PipelineStageIndex;
use VentureDrake\LaravelCrm\Livewire\Settings\PipelineStages\PipelineStageShow;
use VentureDrake\LaravelCrm\Livewire\Settings\ProductCategories\ProductCategoryCreate;
use VentureDrake\LaravelCrm\Livewire\Settings\ProductCategories\ProductCategoryEdit;
use VentureDrake\LaravelCrm\Livewire\Settings\ProductCategories\ProductCategoryIndex;
use VentureDrake\LaravelCrm\Livewire\Settings\ProductCategories\ProductCategoryShow;
use VentureDrake\LaravelCrm\Livewire\Settings\SettingEdit;
use VentureDrake\LaravelCrm\Livewire\Settings\TaxRates\TaxRateCreate;
use VentureDrake\LaravelCrm\Livewire\Settings\TaxRates\TaxRateEdit;
use VentureDrake\LaravelCrm\Livewire\Settings\TaxRates\TaxRateIndex;
use VentureDrake\LaravelCrm\Livewire\Settings\TaxRates\TaxRateShow;
use VentureDrake\LaravelCrm\Livewire\Settings\TemplateSettings;
use VentureDrake\LaravelCrm\Livewire\SmsCampaigns\SmsCampaignCreate;
use VentureDrake\LaravelCrm\Livewire\SmsCampaigns\SmsCampaignEdit;
use VentureDrake\LaravelCrm\Livewire\SmsCampaigns\SmsCampaignIndex;
use VentureDrake\LaravelCrm\Livewire\SmsCampaigns\SmsCampaignShow;
use VentureDrake\LaravelCrm\Livewire\SmsTemplates\SmsTemplateCreate;
use VentureDrake\LaravelCrm\Livewire\SmsTemplates\SmsTemplateEdit;
use VentureDrake\LaravelCrm\Livewire\SmsTemplates\SmsTemplateIndex;
use VentureDrake\LaravelCrm\Livewire\SmsTemplates\SmsTemplateShow;
use VentureDrake\LaravelCrm\Livewire\SystemCheckBanner;
use VentureDrake\LaravelCrm\Livewire\Tasks\TaskCreate;
use VentureDrake\LaravelCrm\Livewire\Tasks\TaskEdit;
use VentureDrake\LaravelCrm\Livewire\Tasks\TaskIndex;
use VentureDrake\LaravelCrm\Livewire\Tasks\TaskItem;
use VentureDrake\LaravelCrm\Livewire\Tasks\TaskRelated;
use VentureDrake\LaravelCrm\Livewire\Tasks\TaskShow;
use VentureDrake\LaravelCrm\Livewire\Teams\TeamCreate;
use VentureDrake\LaravelCrm\Livewire\Teams\TeamEdit;
use VentureDrake\LaravelCrm\Livewire\Teams\TeamIndex;
use VentureDrake\LaravelCrm\Livewire\Teams\TeamShow;
use VentureDrake\LaravelCrm\Livewire\Users\UserCreate;
use VentureDrake\LaravelCrm\Livewire\Users\UserEdit;
use VentureDrake\LaravelCrm\Livewire\Users\UserImport;
use VentureDrake\LaravelCrm\Livewire\Users\UserIndex;
use VentureDrake\LaravelCrm\Livewire\Users\UserInvite;
use VentureDrake\LaravelCrm\Livewire\Users\UserShow;
use VentureDrake\LaravelCrm\Models\Activity;
use VentureDrake\LaravelCrm\Models\Call;
use VentureDrake\LaravelCrm\Models\ChatConversation;
use VentureDrake\LaravelCrm\Models\ChatMessage;
use VentureDrake\LaravelCrm\Models\ChatVisitor;
use VentureDrake\LaravelCrm\Models\ChatWidget;
use VentureDrake\LaravelCrm\Models\Contact;
use VentureDrake\LaravelCrm\Models\Customer;
use VentureDrake\LaravelCrm\Models\Deal;
use VentureDrake\LaravelCrm\Models\Delivery;
use VentureDrake\LaravelCrm\Models\DeliveryProduct;
use VentureDrake\LaravelCrm\Models\Email;
use VentureDrake\LaravelCrm\Models\EmailCampaign;
use VentureDrake\LaravelCrm\Models\EmailCampaignRecipient;
use VentureDrake\LaravelCrm\Models\EmailTemplate;
use VentureDrake\LaravelCrm\Models\Feature;
use VentureDrake\LaravelCrm\Models\FeatureComment;
use VentureDrake\LaravelCrm\Models\FeatureStatus;
use VentureDrake\LaravelCrm\Models\FeatureView;
use VentureDrake\LaravelCrm\Models\FeatureVote;
use VentureDrake\LaravelCrm\Models\Field;
use VentureDrake\LaravelCrm\Models\FieldGroup;
use VentureDrake\LaravelCrm\Models\FieldModel;
use VentureDrake\LaravelCrm\Models\FieldOption;
use VentureDrake\LaravelCrm\Models\FieldValue;
use VentureDrake\LaravelCrm\Models\File;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrm\Models\InvoiceLine;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrm\Models\LeadSource;
use VentureDrake\LaravelCrm\Models\Lunch;
use VentureDrake\LaravelCrm\Models\Meeting;
use VentureDrake\LaravelCrm\Models\Monitor;
use VentureDrake\LaravelCrm\Models\MonitorCheck;
use VentureDrake\LaravelCrm\Models\Note;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\OrderProduct;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Models\Phone;
use VentureDrake\LaravelCrm\Models\Pipeline;
use VentureDrake\LaravelCrm\Models\PipelineStage;
use VentureDrake\LaravelCrm\Models\PipelineStageProbability;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Models\ProductPrice;
use VentureDrake\LaravelCrm\Models\ProductVariation;
use VentureDrake\LaravelCrm\Models\PurchaseOrder;
use VentureDrake\LaravelCrm\Models\PurchaseOrderLine;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrm\Models\QuoteProduct;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrm\Models\SmsCampaign;
use VentureDrake\LaravelCrm\Models\SmsCampaignRecipient;
use VentureDrake\LaravelCrm\Models\SmsTemplate;
use VentureDrake\LaravelCrm\Models\Task;
use VentureDrake\LaravelCrm\Models\Team;
use VentureDrake\LaravelCrm\Models\UserInvitation;
use VentureDrake\LaravelCrm\Models\XeroContact;
use VentureDrake\LaravelCrm\Models\XeroInvoice;
use VentureDrake\LaravelCrm\Models\XeroItem;
use VentureDrake\LaravelCrm\Models\XeroPerson;
use VentureDrake\LaravelCrm\Models\XeroPurchaseOrder;
use VentureDrake\LaravelCrm\Observers\ActivityObserver;
use VentureDrake\LaravelCrm\Observers\CallObserver;
use VentureDrake\LaravelCrm\Observers\ChatConversationObserver;
use VentureDrake\LaravelCrm\Observers\ChatMessageObserver;
use VentureDrake\LaravelCrm\Observers\ChatVisitorObserver;
use VentureDrake\LaravelCrm\Observers\ChatWidgetObserver;
use VentureDrake\LaravelCrm\Observers\ContactObserver;
use VentureDrake\LaravelCrm\Observers\CustomerObserver;
use VentureDrake\LaravelCrm\Observers\DealObserver;
use VentureDrake\LaravelCrm\Observers\DeliveryObserver;
use VentureDrake\LaravelCrm\Observers\DeliveryProductObserver;
use VentureDrake\LaravelCrm\Observers\EmailCampaignObserver;
use VentureDrake\LaravelCrm\Observers\EmailCampaignRecipientObserver;
use VentureDrake\LaravelCrm\Observers\EmailObserver;
use VentureDrake\LaravelCrm\Observers\EmailTemplateObserver;
use VentureDrake\LaravelCrm\Observers\FeatureCommentObserver;
use VentureDrake\LaravelCrm\Observers\FeatureObserver;
use VentureDrake\LaravelCrm\Observers\FeatureStatusObserver;
use VentureDrake\LaravelCrm\Observers\FeatureViewObserver;
use VentureDrake\LaravelCrm\Observers\FeatureVoteObserver;
use VentureDrake\LaravelCrm\Observers\FieldGroupObserver;
use VentureDrake\LaravelCrm\Observers\FieldModelObserver;
use VentureDrake\LaravelCrm\Observers\FieldObserver;
use VentureDrake\LaravelCrm\Observers\FieldOptionObserver;
use VentureDrake\LaravelCrm\Observers\FieldValueObserver;
use VentureDrake\LaravelCrm\Observers\FileObserver;
use VentureDrake\LaravelCrm\Observers\InvoiceLineObserver;
use VentureDrake\LaravelCrm\Observers\InvoiceObserver;
use VentureDrake\LaravelCrm\Observers\LeadObserver;
use VentureDrake\LaravelCrm\Observers\LeadSourceObserver;
use VentureDrake\LaravelCrm\Observers\LunchObserver;
use VentureDrake\LaravelCrm\Observers\MeetingObserver;
use VentureDrake\LaravelCrm\Observers\MonitorCheckObserver;
use VentureDrake\LaravelCrm\Observers\MonitorObserver;
use VentureDrake\LaravelCrm\Observers\NoteObserver;
use VentureDrake\LaravelCrm\Observers\OrderObserver;
use VentureDrake\LaravelCrm\Observers\OrderProductObserver;
use VentureDrake\LaravelCrm\Observers\OrganizationObserver;
use VentureDrake\LaravelCrm\Observers\PersonObserver;
use VentureDrake\LaravelCrm\Observers\PhoneObserver;
use VentureDrake\LaravelCrm\Observers\PipelineObserver;
use VentureDrake\LaravelCrm\Observers\PipelineStageObserver;
use VentureDrake\LaravelCrm\Observers\PipelineStageProbabilityObserver;
use VentureDrake\LaravelCrm\Observers\ProductObserver;
use VentureDrake\LaravelCrm\Observers\ProductPriceObserver;
use VentureDrake\LaravelCrm\Observers\ProductVariationObserver;
use VentureDrake\LaravelCrm\Observers\PurchaseOrderLineObserver;
use VentureDrake\LaravelCrm\Observers\PurchaseOrderObserver;
use VentureDrake\LaravelCrm\Observers\QuoteObserver;
use VentureDrake\LaravelCrm\Observers\QuoteProductObserver;
use VentureDrake\LaravelCrm\Observers\SettingObserver;
use VentureDrake\LaravelCrm\Observers\SmsCampaignObserver;
use VentureDrake\LaravelCrm\Observers\SmsCampaignRecipientObserver;
use VentureDrake\LaravelCrm\Observers\SmsTemplateObserver;
use VentureDrake\LaravelCrm\Observers\TaskObserver;
use VentureDrake\LaravelCrm\Observers\TeamObserver;
use VentureDrake\LaravelCrm\Observers\UserInvitationObserver;
use VentureDrake\LaravelCrm\Observers\UserObserver;
use VentureDrake\LaravelCrm\Observers\XeroContactObserver;
use VentureDrake\LaravelCrm\Observers\XeroInvoiceObserver;
use VentureDrake\LaravelCrm\Observers\XeroItemObserver;
use VentureDrake\LaravelCrm\Observers\XeroPersonObserver;
use VentureDrake\LaravelCrm\Observers\XeroPurchaseOrderObserver;
use VentureDrake\LaravelCrm\Observers\XeroTokenObserver;
use VentureDrake\LaravelCrm\Policies\ActivityPolicy;
use VentureDrake\LaravelCrm\Policies\CallPolicy;
use VentureDrake\LaravelCrm\Policies\ChatConversationPolicy;
use VentureDrake\LaravelCrm\Policies\ChatWidgetPolicy;
use VentureDrake\LaravelCrm\Policies\ContactPolicy;
use VentureDrake\LaravelCrm\Policies\CustomerPolicy;
use VentureDrake\LaravelCrm\Policies\DealPolicy;
use VentureDrake\LaravelCrm\Policies\DeliveryPolicy;
use VentureDrake\LaravelCrm\Policies\EmailCampaignPolicy;
use VentureDrake\LaravelCrm\Policies\EmailTemplatePolicy;
use VentureDrake\LaravelCrm\Policies\FeaturePolicy;
use VentureDrake\LaravelCrm\Policies\FieldGroupPolicy;
use VentureDrake\LaravelCrm\Policies\FieldOptionPolicy;
use VentureDrake\LaravelCrm\Policies\FieldPolicy;
use VentureDrake\LaravelCrm\Policies\FilePolicy;
use VentureDrake\LaravelCrm\Policies\InvoicePolicy;
use VentureDrake\LaravelCrm\Policies\LabelPolicy;
use VentureDrake\LaravelCrm\Policies\LeadPolicy;
use VentureDrake\LaravelCrm\Policies\LeadSourcePolicy;
use VentureDrake\LaravelCrm\Policies\LunchPolicy;
use VentureDrake\LaravelCrm\Policies\MeetingPolicy;
use VentureDrake\LaravelCrm\Policies\MonitorCheckPolicy;
use VentureDrake\LaravelCrm\Policies\MonitorPolicy;
use VentureDrake\LaravelCrm\Policies\NotePolicy;
use VentureDrake\LaravelCrm\Policies\OrderPolicy;
use VentureDrake\LaravelCrm\Policies\OrganizationPolicy;
use VentureDrake\LaravelCrm\Policies\PermissionPolicy;
use VentureDrake\LaravelCrm\Policies\PersonPolicy;
use VentureDrake\LaravelCrm\Policies\PipelinePolicy;
use VentureDrake\LaravelCrm\Policies\PipelineStagePolicy;
use VentureDrake\LaravelCrm\Policies\ProductCategoryPolicy;
use VentureDrake\LaravelCrm\Policies\ProductPolicy;
use VentureDrake\LaravelCrm\Policies\PurchaseOrderPolicy;
use VentureDrake\LaravelCrm\Policies\QuotePolicy;
use VentureDrake\LaravelCrm\Policies\RolePolicy;
use VentureDrake\LaravelCrm\Policies\SettingPolicy;
use VentureDrake\LaravelCrm\Policies\SmsCampaignPolicy;
use VentureDrake\LaravelCrm\Policies\SmsTemplatePolicy;
use VentureDrake\LaravelCrm\Policies\TaskPolicy;
use VentureDrake\LaravelCrm\Policies\TaxRatePolicy;
use VentureDrake\LaravelCrm\Policies\TeamPolicy;
use VentureDrake\LaravelCrm\Policies\UserPolicy;
use VentureDrake\LaravelCrm\Services\SettingService;
use VentureDrake\LaravelCrm\Services\SystemCheckService;
use VentureDrake\LaravelCrm\Support\Modules;
use VentureDrake\LaravelCrm\Support\RouteDomain;
use VentureDrake\LaravelCrm\Support\XeroIntegration;
use VentureDrake\LaravelCrm\View\Components\Addresses;
use VentureDrake\LaravelCrm\View\Components\CustomFields;
use VentureDrake\LaravelCrm\View\Components\CustomFieldValues;
use VentureDrake\LaravelCrm\View\Components\DeleteConfirm;
use VentureDrake\LaravelCrm\View\Components\Emails;
use VentureDrake\LaravelCrm\View\Components\GetLinkButton;
use VentureDrake\LaravelCrm\View\Components\Header;
use VentureDrake\LaravelCrm\View\Components\IndexToggle;
use VentureDrake\LaravelCrm\View\Components\PdfPreview;
use VentureDrake\LaravelCrm\View\Components\PdfPreviewButton;
use VentureDrake\LaravelCrm\View\Components\Phones;
use VentureDrake\LaravelCrm\View\Components\TimelineItem;
use VentureDrake\LaravelCrm\View\Composers\SettingsComposer;

class LaravelCrmServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array
     */
    protected $policies = [
        'App\Models\User' => UserPolicy::class,
        'VentureDrake\LaravelCrm\Models\Team' => TeamPolicy::class,
        'VentureDrake\LaravelCrm\Models\Setting' => SettingPolicy::class,
        'App\Models\Role' => RolePolicy::class,
        'App\Models\Permission' => PermissionPolicy::class,
        'VentureDrake\LaravelCrm\Models\Lead' => LeadPolicy::class,
        'VentureDrake\LaravelCrm\Models\Deal' => DealPolicy::class,
        'VentureDrake\LaravelCrm\Models\Quote' => QuotePolicy::class,
        'VentureDrake\LaravelCrm\Models\Order' => OrderPolicy::class,
        'VentureDrake\LaravelCrm\Models\Invoice' => InvoicePolicy::class,
        'VentureDrake\LaravelCrm\Models\Customer' => CustomerPolicy::class,
        'VentureDrake\LaravelCrm\Models\Person' => PersonPolicy::class,
        'VentureDrake\LaravelCrm\Models\Organization' => OrganizationPolicy::class,
        'VentureDrake\LaravelCrm\Models\Contact' => ContactPolicy::class,
        'VentureDrake\LaravelCrm\Models\Product' => ProductPolicy::class,
        'VentureDrake\LaravelCrm\Models\ProductCategory' => ProductCategoryPolicy::class,
        'VentureDrake\LaravelCrm\Models\TaxRate' => TaxRatePolicy::class,
        'VentureDrake\LaravelCrm\Models\Label' => LabelPolicy::class,
        'VentureDrake\LaravelCrm\Models\LeadSource' => LeadSourcePolicy::class,
        'VentureDrake\LaravelCrm\Models\Activity' => ActivityPolicy::class,
        'VentureDrake\LaravelCrm\Models\Task' => TaskPolicy::class,
        'VentureDrake\LaravelCrm\Models\Note' => NotePolicy::class,
        'VentureDrake\LaravelCrm\Models\Activity' => ActivityPolicy::class,
        'VentureDrake\LaravelCrm\Models\Call' => CallPolicy::class,
        'VentureDrake\LaravelCrm\Models\Meeting' => MeetingPolicy::class,
        'VentureDrake\LaravelCrm\Models\Lunch' => LunchPolicy::class,
        'VentureDrake\LaravelCrm\Models\File' => FilePolicy::class,
        'VentureDrake\LaravelCrm\Models\Feature' => FeaturePolicy::class,
        'VentureDrake\LaravelCrm\Models\Field' => FieldPolicy::class,
        'VentureDrake\LaravelCrm\Models\FieldGroup' => FieldGroupPolicy::class,
        'VentureDrake\LaravelCrm\Models\FieldOption' => FieldOptionPolicy::class,
        'VentureDrake\LaravelCrm\Models\Delivery' => DeliveryPolicy::class,
        'VentureDrake\LaravelCrm\Models\PurchaseOrder' => PurchaseOrderPolicy::class,
        'VentureDrake\LaravelCrm\Models\Pipeline' => PipelinePolicy::class,
        'VentureDrake\LaravelCrm\Models\PipelineStage' => PipelineStagePolicy::class,
        'VentureDrake\LaravelCrm\Models\ChatConversation' => ChatConversationPolicy::class,
        'VentureDrake\LaravelCrm\Models\ChatWidget' => ChatWidgetPolicy::class,
        'VentureDrake\LaravelCrm\Models\EmailCampaign' => EmailCampaignPolicy::class,
        'VentureDrake\LaravelCrm\Models\EmailTemplate' => EmailTemplatePolicy::class,
        'VentureDrake\LaravelCrm\Models\SmsCampaign' => SmsCampaignPolicy::class,
        'VentureDrake\LaravelCrm\Models\SmsTemplate' => SmsTemplatePolicy::class,
        'VentureDrake\LaravelCrm\Models\Monitor' => MonitorPolicy::class,
        'VentureDrake\LaravelCrm\Models\MonitorCheck' => MonitorCheckPolicy::class,
    ];

    /**
     * Bootstrap the application services.
     */
    public function boot(Router $router): void
    {
        Paginator::useBootstrap();

        $this->registerPolicies();

        /*
         * Optional methods to load your package assets
         */
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'laravel-crm');
        // TBC: BS or TW mode, setting on config
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'laravel-crm');
        // database/migrations holds .stub files, which the migrator's *_*.php
        // glob cannot see — loading it was inert. Real, package-loaded
        // migrations live in database/updates and reach every host through a
        // plain `php artisan migrate`, with no publish step and no entry in the
        // (now frozen) publish array below.
        $this->loadMigrationsFrom(__DIR__.'/../database/updates');

        // Middleware
        $router->aliasMiddleware('auth.laravel-crm', Authenticate::class);
        $router->aliasMiddleware('laravel-crm.api.json', ForceJsonResponse::class);
        $router->aliasMiddleware('laravel-crm.api.team', SetApiTeamContext::class);

        RateLimiter::for('laravel-crm-api', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(60)->by($request->user()->getAuthIdentifier())
                : Limit::perMinute(30)->by($request->ip());
        });

        if (config('laravel-crm.teams')) {
            $router->pushMiddlewareToGroup('web', TeamsPermission::class);
            $router->pushMiddlewareToGroup('crm-api', TeamsPermission::class);
            // XeroTenant resolves Dcblogdev\Xero\Models\XeroToken on every request,
            // so it only goes in the stack when the suggested package is installed.
            if (XeroIntegration::installed()) {
                $router->pushMiddlewareToGroup('web', XeroTenant::class);
                $router->pushMiddlewareToGroup('crm-api', XeroTenant::class);
            }
        }

        if (config('laravel-crm.route_subdomain') || RouteDomain::configured()) {
            $router->pushMiddlewareToGroup('crm', RouteSubdomain::class);

            // Check the host before Authenticate, so a guest on the bare
            // domain is sent to the subdomain, not to a login page on the
            // bare domain whose session the subdomain never sees.
            $kernel = $this->app->make(HttpKernel::class);

            if (method_exists($kernel, 'addToMiddlewarePriorityBefore')) {
                $kernel->addToMiddlewarePriorityBefore(AuthenticatesRequests::class, RouteSubdomain::class);
            }
        }

        // Global, not a route group: the bare domain and www. match no CRM
        // route once the routes are bound to route_domain, and group
        // middleware never runs on an unmatched route.
        if (RouteDomain::configured()) {
            $kernel = $this->app->make(HttpKernel::class);

            if (method_exists($kernel, 'pushMiddleware')) {
                $kernel->pushMiddleware(RedirectToRouteDomain::class);
            }
        }

        $router->pushMiddlewareToGroup('crm', Settings::class);
        $router->pushMiddlewareToGroup('crm-api', Settings::class);
        $router->pushMiddlewareToGroup('crm', HasCrmAccess::class);
        $router->pushMiddlewareToGroup('crm-api', HasCrmAccess::class);
        $router->pushMiddlewareToGroup('crm', LastOnlineAt::class);
        $router->pushMiddlewareToGroup('crm', LogUsage::class);
        $router->pushMiddlewareToGroup('crm-api', LogUsage::class);
        $router->pushMiddlewareToGroup('crm', FormComponentsConfig::class);
        $router->pushMiddlewareToGroup('web', middleware: FormComponentsConfig::class);

        $this->registerRoutes();

        // Register Observers
        Lead::observe(LeadObserver::class);
        LeadSource::observe(LeadSourceObserver::class);
        Deal::observe(DealObserver::class);
        Quote::observe(QuoteObserver::class);
        QuoteProduct::observe(QuoteProductObserver::class);
        Order::observe(OrderObserver::class);
        OrderProduct::observe(OrderProductObserver::class);
        Invoice::observe(InvoiceObserver::class);
        InvoiceLine::observe(InvoiceLineObserver::class);
        Customer::observe(CustomerObserver::class);
        Person::observe(PersonObserver::class);
        Organization::observe(OrganizationObserver::class);
        Phone::observe(PhoneObserver::class);
        Email::observe(EmailObserver::class);
        Product::observe(ProductObserver::class);
        ProductPrice::observe(ProductPriceObserver::class);
        ProductVariation::observe(ProductVariationObserver::class);
        Setting::observe(SettingObserver::class);
        Note::observe(NoteObserver::class);
        File::observe(FileObserver::class);
        Contact::observe(ContactObserver::class);
        XeroItem::observe(XeroItemObserver::class);
        XeroContact::observe(XeroContactObserver::class);
        XeroPerson::observe(XeroPersonObserver::class);
        XeroInvoice::observe(XeroInvoiceObserver::class);
        Task::observe(TaskObserver::class);
        Activity::observe(ActivityObserver::class);
        // XeroToken ships with the suggested dcblogdev/laravel-xero package, not with us.
        if (XeroIntegration::installed()) {
            XeroToken::observe(XeroTokenObserver::class);
        }
        Call::observe(CallObserver::class);
        Meeting::observe(MeetingObserver::class);
        Monitor::observe(MonitorObserver::class);
        MonitorCheck::observe(MonitorCheckObserver::class);
        Lunch::observe(LunchObserver::class);
        Field::observe(FieldObserver::class);
        FieldGroup::observe(FieldGroupObserver::class);
        FieldOption::observe(FieldOptionObserver::class);
        FieldModel::observe(FieldModelObserver::class);
        FieldValue::observe(FieldValueObserver::class);
        Delivery::observe(DeliveryObserver::class);
        DeliveryProduct::observe(DeliveryProductObserver::class);
        PurchaseOrder::observe(PurchaseOrderObserver::class);
        PurchaseOrderLine::observe(PurchaseOrderLineObserver::class);
        XeroPurchaseOrder::observe(XeroPurchaseOrderObserver::class);
        Pipeline::observe(PipelineObserver::class);
        PipelineStage::observe(PipelineStageObserver::class);
        PipelineStageProbability::observe(PipelineStageProbabilityObserver::class);

        ChatWidget::observe(ChatWidgetObserver::class);
        ChatVisitor::observe(ChatVisitorObserver::class);
        ChatConversation::observe(ChatConversationObserver::class);
        ChatMessage::observe(ChatMessageObserver::class);

        EmailCampaign::observe(EmailCampaignObserver::class);
        EmailTemplate::observe(EmailTemplateObserver::class);
        EmailCampaignRecipient::observe(EmailCampaignRecipientObserver::class);
        SmsCampaign::observe(SmsCampaignObserver::class);
        SmsTemplate::observe(SmsTemplateObserver::class);
        SmsCampaignRecipient::observe(SmsCampaignRecipientObserver::class);
        Feature::observe(FeatureObserver::class);
        FeatureStatus::observe(FeatureStatusObserver::class);
        FeatureComment::observe(FeatureCommentObserver::class);
        FeatureVote::observe(FeatureVoteObserver::class);
        FeatureView::observe(FeatureViewObserver::class);
        UserInvitation::observe(UserInvitationObserver::class);

        User::observe(UserObserver::class);
        Team::observe(TeamObserver::class);

        // Paginate on Collection
        if (! Collection::hasMacro('paginate')) {
            Collection::macro(
                'paginate',
                function ($perPage = 30, $page = null, $options = []) {
                    $page = $page ?: (Paginator::resolveCurrentPage() ?: 1);

                    return (new LengthAwarePaginator(
                        $this->forPage($page, $perPage),
                        $this->count(),
                        $perPage,
                        $page,
                        $options
                    ))
                        ->withPath('');
                }
            );
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/laravel-crm.php' => config_path('laravel-crm.php'),
            ], 'config');

            // Publishing the views.
            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/laravel-crm'),
            ], 'views');

            // Publishing assets.
            $this->publishes([
                __DIR__.'/../public/vendor/laravel-crm/' => public_path('vendor/laravel-crm'),
                __DIR__.'/../resources/assets' => public_path('vendor/laravel-crm'),
            ], 'assets');

            // Publishing the translation files.
            $this->publishes([
                __DIR__.'/../resources/lang' => resource_path('lang/vendor/laravel-crm'),
            ], 'lang');

            // Publishing the seeders
            if (! class_exists('LaravelCrmTablesSeeder')) {
                if (version_compare(app()->version(), '8', '>=')) {
                    $this->publishes([
                        __DIR__.'/../database/seeders/LaravelCrmTablesSeeder.php' => database_path(
                            'seeders/LaravelCrmTablesSeeder.php'
                        ),
                    ], 'seeders');
                } else {
                    $this->publishes([
                        __DIR__.'/../database/seeders/LaravelCrmTablesSeeder.php' => database_path(
                            'seeds/LaravelCrmTablesSeeder.php'
                        ),
                    ], 'seeders');
                }
            }

            // Registering package commands.
            $this->commands([
                LaravelCrmInstall::class,
                LaravelCrmAddUser::class,
                LaravelCrmUpdate::class,
                LaravelCrmUpgrade::class,
                LaravelCrmPermissions::class,
                LaravelCrmLabels::class,
                LaravelCrmLeadSources::class,
                LaravelCrmAddressTypes::class,
                LaravelCrmOrganizationTypes::class,
                LaravelCrmXero::class,
                LaravelCrmReminders::class,
                LaravelCrmContactTypes::class,
                LaravelCrmFields::class,
                LaravelCrmArchive::class,
                LaravelCrmEncrypt::class,
                LaravelCrmDecrypt::class,
                LaravelCrmV2::class,
                LaravelCrmSampleData::class,
                LaravelCrmEmailCampaignsDispatch::class,
                LaravelCrmSmsCampaignsDispatch::class,
                LaravelCrmMonitorCheck::class,
                IssueApiToken::class,
            ]);

        }

        // View components
        Blade::componentNamespace('VentureDrake\\LaravelCrm\\View\\Components', 'crm');
        Blade::component('crm-index-toggle', IndexToggle::class);
        Blade::component('crm-delete-confirm', DeleteConfirm::class);
        Blade::component('crm-header', Header::class);
        Blade::component('crm-phones', Phones::class);
        Blade::component('crm-emails', Emails::class);
        Blade::component('crm-addresses', Addresses::class);
        Blade::component('crm-timeline-item', TimelineItem::class);
        Blade::component('crm-custom-fields', CustomFields::class);
        Blade::component('crm-custom-field-values', CustomFieldValues::class);
        Blade::component('crm-pdf-preview', PdfPreview::class);
        Blade::component('crm-pdf-preview-button', PdfPreviewButton::class);
        Blade::component('crm-get-link-button', GetLinkButton::class);

        // Livewire components
        Livewire::component('phone-edit', LivePhoneEdit::class);
        Livewire::component('email-edit', LiveEmailEdit::class);
        Livewire::component('address-edit', LiveAddressEdit::class);
        Livewire::component('notes', LiveNotes::class);
        Livewire::component('note', LiveNote::class);
        Livewire::component('tasks', LiveTasks::class);
        Livewire::component('task', LiveTask::class);
        Livewire::component('calls', LiveCalls::class);
        Livewire::component('call', LiveCall::class);
        Livewire::component('meetings', LiveMeetings::class);
        Livewire::component('meeting', LiveMeeting::class);
        Livewire::component('lunches', LiveLunches::class);
        Livewire::component('lunch', LiveLunch::class);
        Livewire::component('files', LiveFiles::class);
        Livewire::component('file', LiveFile::class);
        Livewire::component('related-contact-organizations', LiveRelatedContactOrganization::class);
        Livewire::component('related-contact-people', LiveRelatedContactPerson::class);
        Livewire::component('related-people', LiveRelatedPerson::class);
        Livewire::component('live-lead-form', LiveLeadForm::class);
        Livewire::component('live-lead-board', LiveLeadBoard::class);
        Livewire::component('deal-form', LiveDealForm::class);
        Livewire::component('live-deal-board', LiveDealBoard::class);
        Livewire::component('quote-form', LiveQuoteForm::class);
        Livewire::component('live-quote-board', LiveQuoteBoard::class);
        Livewire::component('notify-toast', NotifyToast::class);
        Livewire::component('quote-items', LiveQuoteItems::class);
        Livewire::component('order-form', LiveOrderForm::class);
        Livewire::component('order-items', LiveOrderItems::class);
        Livewire::component('invoice-form', LiveInvoiceForm::class);
        Livewire::component('delivery-items', LiveDeliveryItems::class);
        Livewire::component('purchase-order-form', LivePurchaseOrderForm::class);
        Livewire::component('activity-menu', LiveActivityMenu::class);
        Livewire::component('xero-connect', XeroConnect::class);
        Livewire::component('activities', LiveActivities::class);
        Livewire::component('send-quote', SendQuote::class);
        Livewire::component('invoice-lines', LiveInvoiceLines::class);
        Livewire::component('send-invoice', SendInvoice::class);
        Livewire::component('pay-invoice', PayInvoice::class);
        Livewire::component('product-form', LiveProductForm::class);
        Livewire::component('purchase-order-lines', LivePurchaseOrderLines::class);
        Livewire::component('fields.create-or-edit', CreateOrEdit::class);
        Livewire::component('send-purchase-order', SendPurchaseOrder::class);
        Livewire::component('delivery-details', LiveDeliveryDetails::class);

        /* Version 2 Livewire Components */
        Livewire::component('crm-system-check', SystemCheckBanner::class);
        Livewire::component('crm-dashboard', Dashboard::class);
        Livewire::component('crm-auth-login', Login::class);
        Livewire::component('crm-auth-forgot-password', ForgotPassword::class);
        Livewire::component('crm-auth-reset-password', ResetPassword::class);
        Livewire::component('crm-kanban-board', KanbanBoard::class);
        Livewire::component('crm-activity-menu', ActivityMenu::class);
        Livewire::component('crm-get-link', GetLink::class);
        Livewire::component('crm-activity-tabs', ActivityTabs::class);
        Livewire::component('crm-lead-index', LeadIndex::class);
        Livewire::component('crm-lead-board', LeadBoard::class);
        Livewire::component('crm-lead-show', LeadShow::class);
        Livewire::component('crm-lead-create', LeadCreate::class);
        Livewire::component('crm-lead-edit', LeadEdit::class);
        Livewire::component('crm-feature-index', FeatureIndex::class);
        Livewire::component('crm-feature-board', FeatureBoard::class);
        Livewire::component('crm-feature-show', FeatureShow::class);
        Livewire::component('crm-feature-create', FeatureCreate::class);
        Livewire::component('crm-feature-edit', FeatureEdit::class);
        Livewire::component('crm-feature-voters', FeatureVoters::class);
        Livewire::component('crm-portal-feature-board', PublicFeatureBoard::class);
        Livewire::component('crm-portal-feature-show', PublicFeatureShow::class);
        Livewire::component('crm-portal-feature-submit', PublicFeatureSubmit::class);
        Livewire::component('crm-task-index', TaskIndex::class);
        Livewire::component('crm-task-show', TaskShow::class);
        Livewire::component('crm-task-create', TaskCreate::class);
        Livewire::component('crm-task-edit', TaskEdit::class);
        Livewire::component('crm-task-item', TaskItem::class);
        Livewire::component('crm-task-related', TaskRelated::class);

        // Chat
        Livewire::component('crm-chat-index', ChatIndex::class);
        Livewire::component('crm-chat-show', ChatShow::class);
        Livewire::component('crm-settings-chat-widget-index', ChatWidgetIndex::class);
        Livewire::component('crm-settings-chat-widget-edit', ChatWidgetEdit::class);
        Livewire::component('crm-settings-chat-widget-show', ChatWidgetShow::class);

        // Email Marketing
        Livewire::component('crm-email-campaign-index', EmailCampaignIndex::class);
        Livewire::component('crm-email-campaign-create', EmailCampaignCreate::class);
        Livewire::component('crm-email-campaign-edit', EmailCampaignEdit::class);
        Livewire::component('crm-email-campaign-show', EmailCampaignShow::class);

        // Monitors
        Livewire::component('crm-monitor-index', MonitorIndex::class);
        Livewire::component('crm-monitor-create', MonitorCreate::class);
        Livewire::component('crm-monitor-edit', MonitorEdit::class);
        Livewire::component('crm-monitor-show', MonitorShow::class);
        Livewire::component('crm-email-template-index', EmailTemplateIndex::class);
        Livewire::component('crm-email-template-create', EmailTemplateCreate::class);
        Livewire::component('crm-email-template-edit', EmailTemplateEdit::class);
        Livewire::component('crm-email-template-show', EmailTemplateShow::class);
        Livewire::component('crm-sms-campaign-index', SmsCampaignIndex::class);
        Livewire::component('crm-sms-campaign-create', SmsCampaignCreate::class);
        Livewire::component('crm-sms-campaign-edit', SmsCampaignEdit::class);
        Livewire::component('crm-sms-campaign-show', SmsCampaignShow::class);
        Livewire::component('crm-sms-template-index', SmsTemplateIndex::class);
        Livewire::component('crm-sms-template-create', SmsTemplateCreate::class);
        Livewire::component('crm-sms-template-edit', SmsTemplateEdit::class);
        Livewire::component('crm-sms-template-show', SmsTemplateShow::class);
        Livewire::component('crm-clicksend-connect', ClickSendConnect::class);
        Livewire::component('crm-deal-index', DealIndex::class);
        Livewire::component('crm-deal-board', DealBoard::class);
        Livewire::component('crm-deal-show', DealShow::class);
        Livewire::component('crm-deal-create', DealCreate::class);
        Livewire::component('crm-deal-edit', DealEdit::class);
        Livewire::component('crm-quote-index', QuoteIndex::class);
        Livewire::component('crm-quote-board', QuoteBoard::class);
        Livewire::component('crm-quote-show', QuoteShow::class);
        Livewire::component('crm-quote-create', QuoteCreate::class);
        Livewire::component('crm-quote-edit', QuoteEdit::class);
        Livewire::component('crm-quote-send', QuoteSend::class);
        Livewire::component('crm-order-index', OrderIndex::class);
        Livewire::component('crm-order-related-index', OrderRelatedIndex::class);
        Livewire::component('crm-order-show', OrderShow::class);
        Livewire::component('crm-order-create', OrderCreate::class);
        Livewire::component('crm-order-edit', OrderEdit::class);
        Livewire::component('crm-invoice-index', InvoiceIndex::class);
        Livewire::component('crm-invoice-related-index', InvoiceRelatedIndex::class);
        Livewire::component('crm-invoice-show', InvoiceShow::class);
        Livewire::component('crm-invoice-create', InvoiceCreate::class);
        Livewire::component('crm-invoice-edit', InvoiceEdit::class);
        Livewire::component('crm-invoice-send', InvoiceSend::class);
        Livewire::component('crm-invoice-pay', InvoicePay::class);
        Livewire::component('crm-delivery-index', DeliveryIndex::class);
        Livewire::component('crm-delivery-related-index', DeliveryRelatedIndex::class);
        Livewire::component('crm-delivery-show', DeliveryShow::class);
        Livewire::component('crm-delivery-create', DeliveryCreate::class);
        Livewire::component('crm-delivery-edit', DeliveryEdit::class);
        Livewire::component('crm-purchase-order-index', PurchaseOrderIndex::class);
        Livewire::component('crm-purchase-order-related-index', PurchaseOrderRelatedIndex::class);
        Livewire::component('crm-purchase-order-show', PurchaseOrderShow::class);
        Livewire::component('crm-purchase-order-create', PurchaseOrderCreate::class);
        Livewire::component('crm-purchase-order-edit', PurchaseOrderEdit::class);
        Livewire::component('crm-purchase-order-send', PurchaseOrderSend::class);
        Livewire::component('crm-person-index', PersonIndex::class);
        Livewire::component('crm-person-create', PersonCreate::class);
        Livewire::component('crm-person-edit', PersonEdit::class);
        Livewire::component('crm-person-show', PersonShow::class);
        Livewire::component('crm-person-import', PersonImport::class);
        Livewire::component('crm-organization-index', OrganizationIndex::class);
        Livewire::component('crm-organization-create', OrganizationCreate::class);
        Livewire::component('crm-organization-edit', OrganizationEdit::class);
        Livewire::component('crm-organization-show', OrganizationShow::class);
        Livewire::component('crm-organization-import', OrganizationImport::class);
        Livewire::component('crm-user-index', UserIndex::class);
        Livewire::component('crm-user-create', UserCreate::class);
        Livewire::component('crm-user-edit', UserEdit::class);
        Livewire::component('crm-user-show', UserShow::class);
        Livewire::component('crm-user-import', UserImport::class);
        Livewire::component('crm-user-invite', UserInvite::class);
        Livewire::component('crm-team-index', TeamIndex::class);
        Livewire::component('crm-team-create', TeamCreate::class);
        Livewire::component('crm-team-edit', TeamEdit::class);
        Livewire::component('crm-team-show', TeamShow::class);
        Livewire::component('crm-product-index', ProductIndex::class);
        Livewire::component('crm-product-create', ProductCreate::class);
        Livewire::component('crm-product-edit', ProductEdit::class);
        Livewire::component('crm-product-show', ProductShow::class);

        Livewire::component('crm-activity-feed', ActivityFeed::class);
        Livewire::component('crm-activity-index', ActivityIndex::class);
        Livewire::component('crm-note-item', NoteItem::class);
        Livewire::component('crm-note-related', NoteRelated::class);
        Livewire::component('crm-call-item', CallItem::class);
        Livewire::component('crm-call-related', CallRelated::class);
        Livewire::component('crm-meeting-item', MeetingItem::class);
        Livewire::component('crm-meeting-related', MeetingRelated::class);
        Livewire::component('crm-lunch-item', LunchItem::class);
        Livewire::component('crm-lunch-related', LunchRelated::class);
        Livewire::component('crm-file-item', FileItem::class);
        Livewire::component('crm-file-related', FileRelated::class);

        Livewire::component('crm-model-phones', ModelPhones::class);
        Livewire::component('crm-model-emails', ModelEmails::class);
        Livewire::component('crm-model-addresses', ModelAddresses::class);
        Livewire::component('crm-model-products', ModelProducts::class);
        Livewire::component('crm-related-people', RelatedPeople::class);
        Livewire::component('crm-related-organizations', RelatedOrganizations::class);
        Livewire::component('crm-related-deals', RelatedDeals::class);

        Livewire::component('crm-settings-edit', SettingEdit::class);
        Livewire::component('crm-template-settings', TemplateSettings::class);

        Livewire::component('crm-profile-update-information', UpdateProfileInformationForm::class);
        Livewire::component('crm-profile-update-password', UpdatePasswordForm::class);
        Livewire::component('crm-profile-browser-sessions', LogoutOtherBrowserSessionsForm::class);
        Livewire::component('crm-profile-delete-user', DeleteUserForm::class);
        Livewire::component('crm-profile-two-factor', TwoFactorAuthenticationForm::class);

        Livewire::component('crm-settings-role-index', RoleIndex::class);
        Livewire::component('crm-settings-role-create', RoleCreate::class);
        Livewire::component('crm-settings-role-edit', RoleEdit::class);
        Livewire::component('crm-settings-role-show', RoleShow::class);

        Livewire::component('crm-settings-pipeline-index', PipelineIndex::class);
        Livewire::component('crm-settings-pipeline-edit', PipelineEdit::class);
        Livewire::component('crm-settings-pipeline-show', PipelineShow::class);

        Livewire::component('crm-settings-pipeline-stage-index', PipelineStageIndex::class);
        Livewire::component('crm-settings-pipeline-stage-edit', PipelineStageEdit::class);
        Livewire::component('crm-settings-pipeline-stage-show', PipelineStageShow::class);

        Livewire::component('crm-settings-product-category-index', ProductCategoryIndex::class);
        Livewire::component('crm-settings-product-category-create', ProductCategoryCreate::class);
        Livewire::component('crm-settings-product-category-edit', ProductCategoryEdit::class);
        Livewire::component('crm-settings-product-category-show', ProductCategoryShow::class);

        Livewire::component('crm-settings-tax-rate-index', TaxRateIndex::class);
        Livewire::component('crm-settings-tax-rate-create', TaxRateCreate::class);
        Livewire::component('crm-settings-tax-rate-edit', TaxRateEdit::class);
        Livewire::component('crm-settings-tax-rate-show', TaxRateShow::class);

        Livewire::component('crm-settings-label-index', LabelIndex::class);
        Livewire::component('crm-settings-label-create', LabelCreate::class);
        Livewire::component('crm-settings-label-edit', LabelEdit::class);
        Livewire::component('crm-settings-label-show', LabelShow::class);

        Livewire::component('crm-settings-lead-source-index', LeadSourceIndex::class);
        Livewire::component('crm-settings-lead-source-create', LeadSourceCreate::class);
        Livewire::component('crm-settings-lead-source-edit', LeadSourceEdit::class);
        Livewire::component('crm-settings-lead-source-show', LeadSourceShow::class);

        Livewire::component('crm-settings-feature-status-index', FeatureStatusIndex::class);
        Livewire::component('crm-settings-feature-status-create', FeatureStatusCreate::class);
        Livewire::component('crm-settings-feature-status-edit', FeatureStatusEdit::class);

        Livewire::component('crm-settings-custom-field-index', CustomFieldIndex::class);
        Livewire::component('crm-settings-custom-field-create', CustomFieldCreate::class);
        Livewire::component('crm-settings-custom-field-edit', CustomFieldEdit::class);
        Livewire::component('crm-settings-custom-field-show', CustomFieldShow::class);

        Livewire::component('crm-settings-custom-field-group-index', CustomFieldGroupIndex::class);
        Livewire::component('crm-settings-custom-field-group-create', CustomFieldGroupCreate::class);
        Livewire::component('crm-settings-custom-field-group-edit', CustomFieldGroupEdit::class);
        Livewire::component('crm-settings-custom-field-group-show', CustomFieldGroupShow::class);

        if ($this->app->runningInConsole()) {
            $this->app->booted(function () {
                $schedule = $this->app->make(Schedule::class);

                $schedule->command('laravelcrm:reminders')
                    ->name('laravelCrmReminders')
                    ->everyMinute()
                    ->withoutOverlapping();

                $schedule->command('laravelcrm:email-campaigns-dispatch')
                    ->name('laravelCrmEmailCampaignsDispatch')
                    ->everyMinute()
                    ->withoutOverlapping();

                $schedule->command('laravelcrm:sms-campaigns-dispatch')
                    ->name('laravelCrmSmsCampaignsDispatch')
                    ->everyMinute()
                    ->withoutOverlapping();

                $schedule->command('laravelcrm:archive')
                    ->name('laravelCrmArchiving')
                    ->daily()
                    ->withoutOverlapping();

                if (! is_array(config('laravel-crm.modules')) || in_array('monitoring', config('laravel-crm.modules'))) {
                    $schedule->command('laravelcrm:monitor-check')
                        ->name('laravelCrmMonitorCheck')
                        ->everyMinute()
                        ->withoutOverlapping();
                }

                if (config('xero.clientId') && config('xero.clientSecret')) {
                    $schedule->command('xero:keep-alive')
                        ->name('laravelCrmXeroKeepAlive')
                        ->everyFiveMinutes();
                    $schedule->command('laravelcrm:xero contacts')
                        ->name('laravelCrmXeroContacts')
                        ->everyTenMinutes()
                        ->withoutOverlapping();
                    $schedule->command('laravelcrm:xero products')
                        ->name('laravelCrmXeroProducts')
                        ->everyTenMinutes()
                        ->withoutOverlapping();
                }
            });
        }

        View::composer('*', SettingsComposer::class);

        Blade::if('hasleadsenabled', fn () => Modules::enabled('leads'));

        Blade::if('hasfeaturesenabled', fn () => Modules::enabled('features'));

        Blade::if('hasdealsenabled', fn () => Modules::enabled('deals'));

        Blade::if('hasquotesenabled', fn () => Modules::enabled('quotes'));

        Blade::if('hasordersenabled', fn () => Modules::enabled('orders'));

        Blade::if('hasinvoicesenabled', fn () => Modules::enabled('invoices'));

        Blade::if('hasdeliveriesenabled', fn () => Modules::enabled('deliveries'));

        Blade::if('haspurchaseordersenabled', fn () => Modules::enabled('purchase-orders'));

        Blade::if('hasteamsenabled', fn () => Modules::enabled('teams'));

        Blade::if('haschatenabled', fn () => Modules::enabled('chat'));

        Blade::if('hasemailmarketingenabled', fn () => Modules::enabled('email-marketing'));

        Blade::if('hassmsmarketingenabled', fn () => Modules::enabled('sms-marketing'));

        Blade::if('hasmonitoringenabled', fn () => Modules::enabled('monitoring'));
    }

    /**
     * Register the application services.
     */
    public function register()
    {
        // Automatically apply the package configuration
        $this->mergeConfigFrom(__DIR__.'/../config/package.php', 'laravel-crm');
        $this->mergeConfigFrom(__DIR__.'/../config/laravel-crm.php', 'laravel-crm');

        // Register the main class to use with the facade
        $this->app->singleton('laravel-crm', function () {
            return new LaravelCrm;
        });

        // Scoped rather than singleton: the service memoises the settings map
        // for the life of the instance, and scoped is the binding Laravel
        // flushes between queued jobs and Octane requests. As a singleton that
        // memo would outlive the request that filled it, so a worker could
        // render mail from settings another process had already changed. Under
        // php-fpm the two are identical — a fresh container per request.
        $this->app->scoped('laravel-crm.settings', function () {
            return new SettingService;
        });

        // Alias so type-hinting SettingService resolves the same instance the
        // ~30 existing app('laravel-crm.settings') call sites already share,
        // instead of auto-wiring a fresh instance per injection.
        $this->app->alias('laravel-crm.settings', SettingService::class);

        // Scoped for the same reason, and because it holds the SettingService
        // above — a singleton here would pin one flushed instance forever.
        $this->app->scoped('laravel-crm.system-check', function ($app) {
            return new SystemCheckService($app->make('laravel-crm.settings'));
        });

        $this->app->alias('laravel-crm.system-check', SystemCheckService::class);

        $this->app->register(LaravelCrmEventServiceProvider::class);
    }

    protected function registerRoutes()
    {
        // Auth routes — only 'web' middleware, no crm/crm-api (those require auth)
        Route::group($this->authRouteConfiguration(), function () {
            if (config('laravel-crm.user_interface')) {
                $this->loadRoutesFrom(__DIR__.'/Http/auth-routes.php');
            }
        });

        // Chat widget embed — registered FIRST and OUTSIDE the web middleware
        // group so it bypasses session/CSRF (visitor uses an opaque token).
        // Also registered without the `crm` route prefix so the embed URL
        // is always /p/chat/{key} regardless of LARAVEL_CRM_ROUTE_PREFIX.
        Route::group(['domain' => null, 'prefix' => null, 'middleware' => []], function () {
            if (config('laravel-crm.user_interface')) {
                $this->loadRoutesFrom(__DIR__.'/Http/chat-embed-routes.php');
            }
        });

        // Email campaign tracking routes — also outside `web` middleware so
        // open pixels and one-click unsubscribe links work without session/CSRF.
        Route::group(['domain' => null, 'prefix' => null, 'middleware' => []], function () {
            if (config('laravel-crm.user_interface')) {
                $this->loadRoutesFrom(__DIR__.'/Http/email-tracking-routes.php');
            }
        });

        // Public portal routes (signed-URL quote/invoice views) — registered
        // OUTSIDE the CRM auth/crm-api middleware so recipients can open the
        // link without logging in, but still inside the `web` group so signed
        // URL validation, sessions, and CSRF on POST work. Mounted at `/p`
        // regardless of LARAVEL_CRM_ROUTE_PREFIX.
        Route::group([
            'domain' => null,
            'prefix' => 'p',
            'middleware' => ['web'],
        ], function () {
            if (config('laravel-crm.user_interface')) {
                $this->loadRoutesFrom(__DIR__.'/Http/portal-routes.php');
            }
        });

        // Main CRM routes — full middleware stack
        Route::group($this->routeConfiguration(), function () {
            if (config('laravel-crm.user_interface')) {
                $this->loadRoutesFrom(__DIR__.'/Http/routes.php');
            }
        });

        // API routes (v2) — JSON-only with named rate limiter
        // Mounted under the CRM route prefix (defaults to `crm/api/v2`).
        // Bound to route_domain when one is configured; otherwise to no
        // domain, as before.
        $apiGroup = [
            'prefix' => trim(config('laravel-crm.route_prefix', 'crm'), '/').'/api/v2',
            'middleware' => ['api', 'laravel-crm.api.json', 'throttle:laravel-crm-api'],
        ];

        if ($domain = RouteDomain::configured()) {
            $apiGroup['domain'] = $domain;
        }

        Route::group($apiGroup, function () {
            $this->loadRoutesFrom(__DIR__.'/Http/api-routes.php');
        });
    }

    protected function authRouteConfiguration()
    {
        // RouteSubdomain sends a login on the bare domain to the subdomain,
        // so the session is started on the host the CRM pages are served from.
        return [
            'domain' => RouteDomain::forRoutes(),
            'prefix' => (config('laravel-crm.route_prefix')) ? config('laravel-crm.route_prefix') : null,
            'middleware' => (config('laravel-crm.route_subdomain') || RouteDomain::configured()) ? ['web', RouteSubdomain::class] : ['web'],
        ];
    }

    protected function routeConfiguration()
    {
        // route_domain when set; otherwise guessed from the request host when
        // route_subdomain is set (see RouteDomain::forRoutes()).
        return [
            'domain' => RouteDomain::forRoutes(),
            'prefix' => (config('laravel-crm.route_prefix')) ? config('laravel-crm.route_prefix') : null,
            'middleware' => array_unique(array_merge(['web', 'crm', 'crm-api'], config('laravel-crm.route_middleware') ?? [])),
        ];
    }

    /**
     * Register the application's policies.
     *
     * @return void
     */
    public function registerPolicies()
    {
        foreach ($this->policies() as $key => $value) {
            Gate::policy($key, $value);
        }
    }

    /**
     * Get the policies defined on the provider.
     *
     * @return array
     */
    public function policies()
    {
        return $this->policies;
    }
}
