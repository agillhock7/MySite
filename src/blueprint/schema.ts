import { z } from 'zod';

export const moduleTypeSchema = z.enum([
  'Hero',
  'ContentGrid',
  'ContentList',
  'QuickActions',
  'FAQ'
]);

const isoTimestampSchema = z
  .string()
  .refine((value) => !Number.isNaN(Date.parse(value)), 'Invalid ISO timestamp');

export const moduleSchema = z.object({
  id: z.string().min(1),
  type: moduleTypeSchema,
  props: z.record(z.any()),
  contentKey: z.string().optional()
});

export const shortcutSchema = z.object({
  label: z.string().min(1),
  action: z.string().min(1)
});

export const blueprintSchema = z.object({
  version: z.number(),
  theme: z.object({
    mode: z.enum(['dark', 'light']),
    accent: z.string().min(1)
  }),
  layout: z.object({
    nav: z.enum(['side', 'top', 'none']),
    density: z.enum(['low', 'medium', 'high'])
  }),
  modules: z.array(moduleSchema),
  shortcuts: z.array(shortcutSchema),
  createdAt: isoTimestampSchema,
  updatedAt: isoTimestampSchema
});

export type Blueprint = z.infer<typeof blueprintSchema>;
export type BlueprintModule = z.infer<typeof moduleSchema>;
export type BlueprintShortcut = z.infer<typeof shortcutSchema>;
