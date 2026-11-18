export default {
    qaturia: {
        input: {
            target: process.env.API_CONTRACT_INPUT ?? './contracts/openapi.json',
        },
        output: {
            mode: 'tags-split',
            target: `${process.env.API_CLIENT_OUTPUT ?? '/tmp/qaturia-orval-client'}/qaturia.ts`,
            schemas: `${process.env.API_CLIENT_OUTPUT ?? '/tmp/qaturia-orval-client'}/models`,
            client: 'fetch',
            baseUrl: '/crm/api/v2',
        },
    },
};
