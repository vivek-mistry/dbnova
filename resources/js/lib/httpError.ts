type ResponseError = Error & { response: { status: number; data: string } };

function hasResponse(error: unknown): error is ResponseError {
    return (
        error instanceof Error &&
        typeof (error as Partial<ResponseError>).response?.data === 'string'
    );
}

/**
 * A readable message from a failed useHttp request: Laravel's JSON
 * "message", the first validation error, or the error itself.
 */
export function httpErrorMessage(error: unknown, fallback: string): string {
    if (hasResponse(error)) {
        try {
            const body = JSON.parse(error.response.data) as {
                message?: string;
                errors?: Record<string, string[]>;
            };
            const firstError = Object.values(body.errors ?? {})[0]?.[0];

            return firstError ?? body.message ?? fallback;
        } catch {
            return fallback;
        }
    }

    return error instanceof Error ? error.message : fallback;
}
