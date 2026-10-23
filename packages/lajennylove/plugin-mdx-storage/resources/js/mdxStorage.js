/**
 * MdxStorage Plugin for NativePHP Mobile
 *
 * @example
 * import { mdxStorage } from '@lajennylove/plugin-mdx-storage';
 *
 * // Execute functionality
 * const result = await mdxStorage.execute({ option1: 'value' });
 *
 * // Get status
 * const status = await mdxStorage.getStatus();
 */

const baseUrl = '/_native/api/call';

/**
 * Internal bridge call function
 * @private
 */
async function bridgeCall(method, params = {}) {
    const response = await fetch(baseUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
        },
        body: JSON.stringify({ method, params })
    });

    const result = await response.json();

    if (result.status === 'error') {
        throw new Error(result.message || 'Native call failed');
    }

    const nativeResponse = result.data;
    if (nativeResponse && nativeResponse.data !== undefined) {
        return nativeResponse.data;
    }

    return nativeResponse;
}

/**
 * Execute the plugin functionality
 * @param {Object} options - Options to pass to the native function
 * @returns {Promise<any>}
 */
export async function execute(options = {}) {
    return bridgeCall('MdxStorage.Execute', options);
}

/**
 * Get the current status
 * @returns {Promise<Object>}
 */
export async function getStatus() {
    return bridgeCall('MdxStorage.GetStatus');
}

/**
 * MdxStorage namespace object
 */
export const mdxStorage = {
    execute,
    getStatus
};

export default mdxStorage;