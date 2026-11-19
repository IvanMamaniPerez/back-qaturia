import { fileURLToPath } from 'node:url';

export default {
    qaturia: {
        input: {
            target: process.env.API_CONTRACT_INPUT ?? fileURLToPath(new URL('../../contracts/openapi.json', import.meta.url)),
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
