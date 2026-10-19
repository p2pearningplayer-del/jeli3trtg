const fs = require('fs');

// Create mock browser DOM environment
const elements = {};
function getOrCreateEl(id) {
    if (!elements[id]) {
        elements[id] = {
            id,
            innerText: '',
            textContent: '',
            innerHTML: '',
            value: '',
            disabled: false,
            classList: {
                add: () => {},
                remove: () => {},
                toggle: () => {},
                contains: () => false
            }
        };
    }
    return elements[id];
}

global.document = {
    getElementById: (id) => getOrCreateEl(id),
    querySelectorAll: () => [],
    addEventListener: () => {},
    body: { classList: { add: () => {}, remove: () => {} } }
};

global.window = {
    location: { search: '', hash: '' },
    addEventListener: () => {},
    history: { replaceState: () => {} }
};

global.localStorage = {
    getItem: () => null,
    setItem: () => {}
};

// Load admin.js
const code = fs.readFileSync('public/js/admin.js', 'utf8');
eval(code);

let passed = 0;
let total = 0;
function assertCheck(name, cond) {
    total++;
    if (cond) {
        passed++;
        console.log(`  [PASS] ${name}`);
    } else {
        console.error(`  [FAIL] ${name}`);
    }
}

console.log('=== Headless DOM & JavaScript Execution Verification ===');

// Test 1: formatCurrency existence and formatting
assertCheck('formatCurrency(1000) === "1,000.00"', typeof formatCurrency === 'function' && formatCurrency(1000) === '1,000.00');
assertCheck('formatCurrency(0) === "0.00"', formatCurrency(0) === '0.00');
assertCheck('formatCurrency(null) === "0.00"', formatCurrency(null) === '0.00');

// Test 2: Mock loadAdminAgents with empty list
global.fetch = async (url) => {
    if (url.includes('action=list_agents')) {
        return {
            json: async () => ({ success: true, agents: [] })
        };
    }
    if (url.includes('action=fraud_assessments')) {
        return {
            json: async () => ({ success: true, total: 0, assessments: [] })
        };
    }
    return { json: async () => ({ success: false }) };
};

(async () => {
    // Run loadAdminAgents
    await loadAdminAgents();
    const agentTbody = document.getElementById('adminAgentsTableBody');
    assertCheck('loadAdminAgents with empty list displays clean empty state without communication error', 
        agentTbody.innerHTML.includes('No agent accounts found matching criteria') && !agentTbody.innerHTML.includes('Server communication error'));

    // Run loadAdminAgents with mock agent
    global.fetch = async (url) => ({
        json: async () => ({
            success: true,
            agents: [{
                id: 1,
                agent_code: 'AGT1001',
                name: 'Alpha Agent',
                phone: '9900000001',
                email: 'alpha@jelipay.in',
                root_referral_code: 'ROOT01',
                root_name: 'Root User',
                commission_rate: 3.5,
                eligible_levels: 'A,B,C',
                status: 'active',
                total_referred_users: 5,
                total_volume: 50000,
                total_commission_earned: 1750,
                current_week_commission: 350
            }]
        })
    });

    await loadAdminAgents();
    assertCheck('loadAdminAgents with agent records renders formatted agent table without communication error', 
        agentTbody.innerHTML.includes('AGT1001') && !agentTbody.innerHTML.includes('Server communication error'));
    assertCheck('agentTotalVolume element displays formatted currency', 
        document.getElementById('agentTotalVolume').textContent === '₹50,000.00');

    // Run loadFraudLedger with empty list
    global.fetch = async (url) => ({
        json: async () => ({
            success: true,
            total: 0,
            assessments: []
        })
    });

    await loadAdminFraudAssessments();
    const fraudTbody = document.getElementById('adminFraudTableBody');
    assertCheck('loadAdminFraudAssessments with empty list displays clean empty state without communication error',
        fraudTbody.innerHTML.includes('No fraud assessments found matching') && !fraudTbody.innerHTML.includes('Communication error loading fraud ledger'));

    console.log(`\nDOM Verification Result: ${passed} / ${total} Passed (${Math.round((passed/total)*100)}%)`);
    if (passed === total) {
        process.exit(0);
    } else {
        process.exit(1);
    }
})();
