// PayXbills product details shown on the website.
// TODO: replace the placeholder phone, email and address with Fivestar Technologies' real ones.
export const platform = {
    name: 'PayXbills',
    vendor: 'Fivestar Technologies',
    phone: '+234 800 000 0000',
    email: 'hello@payxbills.com',
    address: 'Fivestar Technologies, Nigeria',
    hours: 'Mon – Fri, 8:00am – 6:00pm',
};

// Who uses PayXbills
export const audiences = [
    'Facility management companies',
    'Hostels and student housing',
    'Estates and residential blocks',
    'Markets and shopping plazas',
    'Office and commercial buildings',
    'Cleaning and service contractors',
];

export const features = [
    {
        key: 'facilities',
        icon: 'building',
        title: 'Facilities and spaces',
        text: 'Add every building you manage and its rooms, flats, shops or stalls. See at a glance what is occupied and what is vacant.',
        points: ['Unlimited facilities', 'Add spaces one by one or by number range', 'Supervisors assigned per facility'],
    },
    {
        key: 'users',
        icon: 'users',
        title: 'Facility users',
        text: 'Register the people who occupy your spaces. They get their own login to see what they owe and pay online.',
        points: ['Login details sent automatically', 'Move users between spaces', 'Removals approved by your admin'],
    },
    {
        key: 'billing',
        icon: 'invoice',
        title: 'Automatic monthly billing',
        text: 'Set a billing category and amount once. Every month PayXbills issues the invoice and sends the pay link by email and SMS.',
        points: ['Billing day you choose', 'Pay for several months at once', 'Credit carried forward'],
    },
    {
        key: 'payments',
        icon: 'card',
        title: 'Online and manual payments',
        text: 'Facility users pay by card or transfer through your own Paystack account. Cash and bank payments are cleared by your accountant with proof.',
        points: ['Money goes straight to you', 'Proof of payment on file', 'Instant receipts'],
    },
    {
        key: 'reminders',
        icon: 'bell',
        title: 'Reminders that get you paid',
        text: 'Overdue users are reminded on the days you choose, with the amount and the pay link. No more chasing by phone.',
        points: ['Email and SMS', 'Your company name as sender', 'Full delivery log'],
    },
    {
        key: 'reports',
        icon: 'chart',
        title: 'Reports and analytics',
        text: 'Know what was billed, collected and is still owed, by facility and category. Download any report as a spreadsheet.',
        points: ['Aged receivables', 'Collection rate', 'Expenditure by facility'],
    },
];
