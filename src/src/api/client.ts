import axios, { type AxiosError, type InternalAxiosRequestConfig } from 'axios';

export interface ApiError {
  type: string;
  title: string;
  status: number;
  detail: string;
  errors?: Record<string, string[]>;
}

const apiClient = axios.create({
  baseURL: '/api/v1',
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
  timeout: 30000,
});

apiClient.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  if (typeof window === 'undefined') return config;

  const token = localStorage.getItem('access_token');
  if (token && config.headers) {
    config.headers.Authorization = `Bearer ${token}`;
  }

  const locale = localStorage.getItem('locale') || 'en';
  if (config.headers) {
    config.headers['Accept-Language'] = locale;
  }

  // Hybrid tenant resolution: in production the subdomain identifies the tenant,
  // but for shared-URL flows (local dev, mobile apps, API tools) we send X-Tenant
  // from the persisted login context.
  const hostParts = window.location.host.split('.');
  const hasSubdomain = hostParts.length >= 3
    || (hostParts.length === 2 && !['localhost', 'test'].includes(hostParts[1].split(':')[0]));

  if (!hasSubdomain) {
    const tenant = localStorage.getItem('tenant');
    if (tenant && config.headers) {
      config.headers['X-Tenant'] = tenant;
    }
  }

  return config;
});

apiClient.interceptors.response.use(
  (response) => response,
  async (error: AxiosError<ApiError>) => {
    const originalRequest = error.config;

    if (error.response?.status === 401 && originalRequest && !originalRequest._retry) {
      originalRequest._retry = true;

      try {
        const { data } = await axios.post(
          '/api/v1/auth/refresh',
          {},
          { withCredentials: true }
        );

        if (data.access_token) {
          localStorage.setItem('access_token', data.access_token);

          if (originalRequest.headers) {
            originalRequest.headers.Authorization = `Bearer ${data.access_token}`;
          }

          return apiClient(originalRequest);
        }
      } catch {
        localStorage.removeItem('access_token');
        window.location.href = '/login';
      }
    }

    return Promise.reject(error);
  }
);

declare module 'axios' {
  interface InternalAxiosRequestConfig {
    _retry?: boolean;
  }
}

export { apiClient };
